# POLICY: Comprehensive Error Handling
**Version:** 1.0

---

## 1. NEVER SWALLOW ERRORS
Empty catch blocks are the most dangerous code pattern. They hide failures, make debugging impossible, and produce subtle data corruption.

**BAD:**
```ts
try { await doSomething() } catch {}
try { await doSomething() } catch (e) { console.log(e) }  // log is not enough
```

**GOOD:**
```ts
try {
  await doSomething()
} catch (error) {
  logger.error("doSomething failed", { error, context: { userId, requestId } })
  throw error  // or return { ok: false, error: toAppError(error) }
}
```

Rule: Every catch block must either re-throw, return a typed error, or log with enough context to diagnose the failure in production.

---

## 2. TYPED ERROR HIERARCHY
Define domain error types rather than throwing generic Error objects:

```ts
class AppError extends Error {
  constructor(
    message: string,
    public readonly code: ErrorCode,
    public readonly statusHint?: number
  ) { super(message); this.name = "AppError" }
}

class ValidationError extends AppError {
  constructor(public readonly fields: Record<string, string[]>) {
    super("Validation failed", "VALIDATION_FAILED", 400)
  }
}
class NotFoundError extends AppError { ... }
class ForbiddenError extends AppError { ... }
class ExternalServiceError extends AppError { ... }
```

Benefits: type-safe error handling, structured logs, consistent HTTP status mapping.

---

## 3. USER-FACING ERROR FEEDBACK
Every error that affects the user must surface a clear, actionable message:

- Network errors: "Could not connect. Check your connection and try again."
- Validation errors: Show field-level messages beside the failing input
- Unauthorized: "Your session has expired. Sign in again."
- Not found: Show a helpful 404 with navigation options, not a blank screen
- Server error: "Something went wrong on our end. Try again in a moment."

Do NOT expose raw error messages, stack traces, or internal codes to users.

---

## 4. ERROR BOUNDARIES IN REACT
Wrap every independent section of the UI in an `ErrorBoundary`:
- Page-level: catches catastrophic render failures
- Widget-level: prevents one broken widget from killing the whole page
- Data-fetching boundaries: use Suspense + `error.tsx` in Next.js App Router

Provide fallback UI in every boundary:

```jsx
<ErrorBoundary fallback={<WidgetErrorCard title="Failed to load chart" />}>
  <ChartWidget data={data} />
</ErrorBoundary>
```

---

## 5. ASYNC OPERATION STATES
Every async operation exposed in UI must handle all 4 states explicitly:

`idle → loading → success`
`              → error (with retry mechanism)`

Never leave the error state with no recovery path:
- **GOOD:** Error message + "Try again" button that re-triggers the operation
- **BAD:** Red error text with no way to recover without a page refresh

