import useDialogFocus from "./useDialogFocus";
import { useRef, useState } from "react";
import { ArrowUpRight, CreditCard, Package, RefreshCw, X } from "lucide-react";

export default function CheckoutModal({
  products,
  onClose,
  onCreate,
  submitting,
  error,
  formatPrice,
}) {
  const [selectedId, setSelectedId] = useState(
    products[1]?.id || products[0]?.id,
  );
  const selected =
    products.find((product) => product.id === selectedId) || products[0];
  const dialog = useRef(null);
  useDialogFocus(dialog, onClose, submitting);
  return (
    <div className="modal-backdrop">
      <div
        ref={dialog}
        tabIndex={-1}
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="checkout-title"
        aria-busy={submitting}
      >
        <div className="modal-head">
          <div>
            <p className="eyebrow">TEST MODE CHECKOUT</p>
            <h2 id="checkout-title">Start secure checkout</h2>
            <p>Choose a product to create a safe demo transaction.</p>
          </div>
          <button
            className="icon-btn"
            onClick={onClose}
            aria-label="Close"
            disabled={submitting}
          >
            <X size={18} />
          </button>
        </div>
        {products.length ? (
          <>
            <div className="checkout-products">
              {products.map((product) => (
                <button
                  key={product.id}
                  className={`checkout-product ${selected?.id === product.id ? "selected" : ""}`}
                  aria-pressed={selected?.id === product.id}
                  disabled={submitting}
                  onClick={() => setSelectedId(product.id)}
                >
                  <div className={`product-icon ${product.color}`}>
                    <Package size={18} />
                  </div>
                  <span>
                    <strong>{product.name}</strong>
                    <small>{product.detail}</small>
                  </span>
                  <b>{formatPrice(product.amount, product.currency)}</b>
                </button>
              ))}
            </div>
            <div className="test-card">
              <CreditCard size={16} />
              <div>
                <strong>Test card</strong>
                <span>4242 4242 4242 4242 · any future date · any CVC</span>
              </div>
            </div>
          </>
        ) : (
          <p>No active products are available.</p>
        )}
        {error && (
          <p className="checkout-error" id="checkout-error" role="alert">
            {error}
          </p>
        )}
        <div className="modal-actions">
          <button className="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button
            className="primary checkout-submit"
            disabled={!selected || submitting}
            aria-describedby={error ? "checkout-error" : undefined}
            onClick={() => selected && onCreate(selected)}
          >
            {submitting ? (
              <>
                <RefreshCw size={15} className="spin" /> Creating checkout…
              </>
            ) : (
              <>
                Create test payment <ArrowUpRight size={15} />
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
