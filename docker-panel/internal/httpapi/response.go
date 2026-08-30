package httpapi

import (
	"encoding/json"
	"net/http"
)

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

func writeSuccess(w http.ResponseWriter, message string, data any) {
	writeJSON(w, http.StatusOK, map[string]any{
		"success": true,
		"message": message,
		"data":    data,
	})
}

func writeError(w http.ResponseWriter, r *http.Request, status int, code string, err error) {
	writeJSON(w, status, map[string]any{
		"success":   false,
		"error":     err.Error(),
		"code":      code,
		"requestId": requestIDFromContext(r.Context()),
	})
}

func writeNotImplemented(w http.ResponseWriter, r *http.Request, feature string) {
	writeJSON(w, http.StatusNotImplemented, map[string]any{
		"success":   false,
		"error":     feature + " is read-only in the Go phase 1 backend.",
		"code":      "PHASE_1_READ_ONLY",
		"requestId": requestIDFromContext(r.Context()),
	})
}
