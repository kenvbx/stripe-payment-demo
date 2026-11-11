import { useEffect, useRef } from "react";

export default function useDialogFocus(
  ref,
  onClose,
  busy = false,
  enabled = true,
) {
  const state = useRef({ onClose, busy });
  state.current = { onClose, busy };
  useEffect(() => {
    if (!enabled) return;
    const previous = document.activeElement;
    const bodyOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const fields = () =>
      [
        ...(ref.current?.querySelectorAll(
          'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), [tabindex="0"]',
        ) || []),
      ].filter((element) => element.getClientRects().length > 0);
    (
      ref.current?.querySelector("[autofocus], input:not(:disabled)") ||
      fields()[0] ||
      ref.current
    )?.focus();
    function handleKey(event) {
      if (event.key === "Escape") {
        event.preventDefault();
        if (!state.current.busy) state.current.onClose();
      }
      if (event.key !== "Tab") return;
      const elements = fields(),
        first = elements[0],
        last = elements.at(-1);
      if (!first) {
        event.preventDefault();
        ref.current?.focus();
        return;
      }
      if (
        event.shiftKey &&
        (document.activeElement === first ||
          !ref.current?.contains(document.activeElement))
      ) {
        event.preventDefault();
        last.focus();
      } else if (
        !event.shiftKey &&
        (document.activeElement === last ||
          !ref.current?.contains(document.activeElement))
      ) {
        event.preventDefault();
        first.focus();
      }
    }
    document.addEventListener("keydown", handleKey);
    return () => {
      document.removeEventListener("keydown", handleKey);
      document.body.style.overflow = bodyOverflow;
      if (previous?.isConnected) previous.focus();
    };
  }, [ref, enabled]);
}
