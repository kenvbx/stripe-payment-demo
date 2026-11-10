<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'notes' => $this->notes,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'staff' => new StaffResource($this->whenLoaded('staff')),
        ];
    }
}
