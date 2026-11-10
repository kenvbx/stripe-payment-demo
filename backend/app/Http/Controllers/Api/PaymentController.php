<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Models\Payment; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB; use Stripe\StripeClient;
class PaymentController extends Controller {
 public function index(Request $request): JsonResponse { return response()->json(Payment::query()->latest()->paginate(min($request->integer('per_page',25),100))); }
 public function createCheckout(Request $request): JsonResponse {
  $data=$request->validate(['product_name'=>['required','string','max:120'],'amount'=>['required','integer','min:50'],'customer_name'=>['required','string','max:120'],'customer_email'=>['required','email'],'idempotency_key'=>['required','string','max:120']]);
  if($existing=Payment::where('idempotency_key',$data['idempotency_key'])->first()) return response()->json(['data'=>$existing,'idempotent_replay'=>true]);
  $payment=DB::transaction(function() use($data): Payment { $payment=Payment::create($data+['currency'=>'usd','status'=>'created','webhook_status'=>'pending']); if(config('services.stripe.secret')) { $intent=(new StripeClient(config('services.stripe.secret')))->paymentIntents->create(['amount'=>$payment->amount,'currency'=>$payment->currency,'metadata'=>['payment_id'=>(string)$payment->id]],['idempotency_key'=>$payment->idempotency_key]); $payment->update(['stripe_payment_intent_id'=>$intent->id,'status'=>$intent->status]); } else { $payment->update(['stripe_payment_intent_id'=>'pi_demo_'.$payment->id,'status'=>'processing']); } return $payment->fresh(); });
  return response()->json(['data'=>$payment],201);
 }
 public function retryWebhook(Payment $payment): JsonResponse { $payment->update(['webhook_status'=>'processed','status'=>$payment->status==='failed'?'succeeded':$payment->status,'last_error'=>null]); return response()->json(['data'=>$payment->fresh()]); }
}
