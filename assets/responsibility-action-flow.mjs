export async function settleResponsibilityAction({
  submit,
  isCurrent,
  onSuccess,
  onConflict,
  onHookError,
}) {
  try {
    const value = await submit();
    if (!isCurrent()) return { outcome: "success", current: false, value };
    try {
      await onSuccess?.(value, { isCurrent });
    } catch (error) {
      if (isCurrent()) onHookError?.(error);
    }
    return { outcome: "success", current: isCurrent(), value };
  } catch (error) {
    if (!isCurrent()) {
      return { outcome: error?.status === 409 ? "conflict" : "error", current: false, error };
    }
    if (error?.status === 409) {
      try {
        await onConflict?.({ isCurrent });
      } catch (hookError) {
        if (isCurrent()) onHookError?.(hookError);
      }
      return { outcome: "conflict", current: isCurrent(), error };
    }
    return { outcome: "error", current: true, error };
  }
}

export function validationAlertItems(result, fieldLabels = {}, fieldLabel = () => "") {
  return Object.entries(result?.errors || {}).map(([name, message]) => {
    const label = fieldLabels[name] || fieldLabel(name) || name;
    return String(message).toLowerCase().includes("required")
      ? `${label} — required`
      : String(message).replace(`${label}: `, "");
  });
}
