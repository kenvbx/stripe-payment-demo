<?php

namespace App\Http\Controllers\Api;

use App\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Jobs\SendAppointmentConfirmation;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\StaffProfile;
use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Appointment::class);
        $query = Appointment::query();

        if ($request->user()->role === 'staff') {
            $query->where('staff_profile_id', $request->user()->staffProfile?->id ?? 0);
        }

        $appointments = $query
            ->with(['customer', 'service', 'staff'])
            ->when($request->filled('staff_profile_id'), fn ($query) => $query->where('staff_profile_id', $request->integer('staff_profile_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn ($query) => $query->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('ends_at', '<=', Carbon::parse($request->input('to'), config('app.timezone'))->endOfDay()))
            ->orderBy('starts_at')
            ->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return AppointmentResource::collection($appointments);
    }

    public function store(Request $request, DatabaseManager $database): AppointmentResource
    {
        $data = $request->validate($this->rules());
        $data['timezone'] ??= config('app.timezone', 'Asia/Ho_Chi_Minh');
        $data['status'] ??= 'pending';
        $data['created_by'] = $request->user()->id;
        Gate::authorize('create', new Appointment($data));

        $appointment = $database->transaction(function () use ($data, $request): Appointment {
            StaffProfile::query()->whereKey($data['staff_profile_id'])->lockForUpdate()->firstOrFail();

            if (! empty($data['idempotency_key'])) {
                $existing = Appointment::query()->where('idempotency_key', $data['idempotency_key'])->first();

                if ($existing) {
                    return $existing;
                }
            }

            $this->ensureNoConflict($data);
            $appointment = Appointment::create($data);
            $appointment->statusHistory()->create([
                'to_status' => $appointment->status,
                'changed_by' => $request->user()?->id,
                'reason' => $data['status_reason'] ?? null,
            ]);
            AuditLog::record('appointment.created', $appointment, ['status' => $appointment->status]);

            return $appointment;
        });

        if ($appointment->status !== AppointmentStatus::Cancelled->value) {
            SendAppointmentConfirmation::dispatch($appointment->id)->afterCommit();

            if ($appointment->starts_at->isFuture()) {
                SendAppointmentReminder::dispatch($appointment->id)
                    ->delay($appointment->starts_at->copy()->subHour())
                    ->afterCommit();
            }
        }

        return new AppointmentResource($appointment->load(['customer', 'service', 'staff']));
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        Gate::authorize('view', $appointment);

        return new AppointmentResource($appointment->load(['customer', 'service', 'staff', 'statusHistory']));
    }

    public function update(Request $request, Appointment $appointment, DatabaseManager $database): AppointmentResource
    {
        Gate::authorize('update', $appointment);
        $data = $request->validate($this->rules(true));
        $database->transaction(function () use ($appointment, $data, $request): void {
            if (isset($data['starts_at']) || isset($data['ends_at']) || isset($data['staff_profile_id'])) {
                $booking = array_merge($appointment->only(['staff_profile_id', 'starts_at', 'ends_at']), $data);
                $targetStaff = StaffProfile::query()->whereKey($booking['staff_profile_id'])->lockForUpdate()->firstOrFail();
                if (isset($data['staff_profile_id'])) {
                    Gate::authorize('reassign', [$appointment, $targetStaff]);
                }
                $this->ensureNoConflict($booking, $appointment->id);
            }
            $oldStatus = $appointment->status;
            $this->validateStatusTransition($oldStatus, $data['status'] ?? null, $data['status_reason'] ?? null);
            $appointment->update($data);
            if (isset($data['status']) && $oldStatus !== $data['status']) {
                $appointment->statusHistory()->create([
                    'from_status' => $oldStatus,
                    'to_status' => $data['status'],
                    'changed_by' => $request->user()?->id,
                    'reason' => $data['status_reason'] ?? null,
                ]);
            }
            AuditLog::record('appointment.updated', $appointment, ['changed_fields' => array_keys($appointment->getChanges())]);
        });

        return new AppointmentResource($appointment->refresh()->load(['customer', 'service', 'staff']));
    }

    public function destroy(Request $request, Appointment $appointment): Response
    {
        Gate::authorize('delete', $appointment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->validateStatusTransition($appointment->status, AppointmentStatus::Cancelled->value, $data['reason']);
        $appointment->update(['status' => AppointmentStatus::Cancelled->value]);
        $appointment->statusHistory()->create([
            'from_status' => $appointment->getOriginal('status'),
            'to_status' => AppointmentStatus::Cancelled->value,
            'changed_by' => $request->user()?->id,
            'reason' => $data['reason'],
        ]);
        AuditLog::record('appointment.cancelled', $appointment, ['reason' => $data['reason']]);

        return response()->noContent();
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'customer_id' => [$required, 'integer', 'exists:customers,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'staff_profile_id' => [$required, 'integer', 'exists:staff_profiles,id'],
            'title' => [$required, 'string', 'max:180'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'status' => ['sometimes', Rule::in(AppointmentStatus::values())],
            'status_reason' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', 'timezone'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function validateStatusTransition(string $currentStatus, ?string $nextStatus, ?string $reason): void
    {
        if ($nextStatus === null || $currentStatus === $nextStatus) {
            return;
        }

        $current = AppointmentStatus::from($currentStatus);
        $next = AppointmentStatus::from($nextStatus);

        if (! $current->canTransitionTo($next)) {
            throw ValidationException::withMessages(['status' => "Cannot change appointment status from {$current->value} to {$next->value}."]);
        }

        if (in_array($next, [AppointmentStatus::Cancelled, AppointmentStatus::Rescheduled], true) && blank($reason)) {
            throw ValidationException::withMessages(['status_reason' => 'A reason is required for cancellation or rescheduling.']);
        }
    }

    private function ensureNoConflict(array $data, ?int $ignoreId = null): void
    {
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        $conflict = Appointment::query()
            ->where('staff_profile_id', $data['staff_profile_id'])
            ->whereNotIn('status', ['cancelled'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['starts_at' => 'This staff member already has an overlapping appointment.']);
        }
    }
}
