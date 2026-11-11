import useDialogFocus from "./useDialogFocus";
import { useRef, useState } from "react";
import { ShieldCheck, X } from "lucide-react";

export default function AdminLoginModal({ onLogin, onClose }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const dialog = useRef(null);
  useDialogFocus(dialog, onClose, submitting);

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      await onLogin(email, password);
    } catch (failure) {
      setError(failure.message || "Sign in failed. Please try again.");
      setPassword("");
    } finally {
      setSubmitting(false);
    }
  }
  return (
    <div className="modal-backdrop">
      <form
        ref={dialog}
        tabIndex={-1}
        className="modal admin-login"
        role="dialog"
        aria-modal="true"
        aria-labelledby="admin-login-title"
        onSubmit={submit}
      >
        <div className="modal-head">
          <div>
            <p className="eyebrow">ADMINISTRATOR ACCESS</p>
            <h2 id="admin-login-title">Admin sign in</h2>
          </div>
          <button
            type="button"
            className="icon-btn"
            aria-label="Close sign in"
            onClick={onClose}
            disabled={submitting}
          >
            <X size={18} />
          </button>
        </div>
        <p>
          Sign in to view payment records, inspect webhooks, and manage test
          refunds.
        </p>
        <label htmlFor="admin-email">
          Email
          <input
            id="admin-email"
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            disabled={submitting}
          />
        </label>
        <label htmlFor="admin-password">
          Password
          <input
            id="admin-password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            disabled={submitting}
          />
        </label>
        {error && (
          <p className="admin-error" role="alert">
            {error}
          </p>
        )}
        <div className="modal-actions">
          <button
            type="button"
            className="outline"
            onClick={onClose}
            disabled={submitting}
          >
            Cancel
          </button>
          <button className="primary" type="submit" disabled={submitting}>
            <ShieldCheck size={16} />
            {submitting ? "Signing in…" : "Sign in"}
          </button>
        </div>
      </form>
    </div>
  );
}
