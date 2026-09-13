(function () {
  const url = new URL(window.location.href);
  if (url.searchParams.get("booking_success") !== "1") return;

  const reference = String(url.searchParams.get("booking_ref") || "").replace(/[^A-Za-z0-9-]/g, "");
  const noticeKey = `booking-payment-success:${reference || "latest"}`;

  url.searchParams.delete("booking_success");
  url.searchParams.delete("booking_ref");
  if (window.history?.replaceState) {
    window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
  }

  if (sessionStorage.getItem(noticeKey) === "shown") return;
  sessionStorage.setItem(noticeKey, "shown");

  const title = "Payment successful — booking submitted!";
  const message = reference
    ? `Your payment was verified and booking ${reference} has been submitted successfully.`
    : "Your payment was verified and your booking has been submitted successfully.";

  const showConfirmation = () => {
    if (window.Swal?.fire) {
      window.Swal.fire({
        icon: "success",
        title,
        html: `${message}<br><small>You can track its approval and remaining balance in your Tourist Profile.</small>`,
        confirmButtonText: "Continue",
        confirmButtonColor: "#287a66"
      });
      return;
    }

    const overlay = document.createElement("div");
    overlay.setAttribute("role", "dialog");
    overlay.setAttribute("aria-modal", "true");
    overlay.style.cssText = "position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:20px;background:rgba(10,36,30,.5)";
    overlay.innerHTML = `
      <div style="width:min(430px,100%);padding:30px;border-radius:18px;background:#fff;box-shadow:0 24px 70px rgba(0,0,0,.25);text-align:center;font-family:Arial,sans-serif;color:#173a32">
        <div style="width:58px;height:58px;margin:0 auto 16px;border-radius:50%;display:grid;place-items:center;background:#e2f3ec;color:#287a66;font-size:32px">✓</div>
        <h2 style="margin:0 0 12px;font-size:22px">${title}</h2>
        <p style="margin:0 0 8px;line-height:1.55;color:#4c665f">${message}</p>
        <p style="margin:0 0 22px;font-size:13px;color:#6b7d78">You can track its approval and remaining balance in your Tourist Profile.</p>
        <button type="button" style="border:0;border-radius:10px;padding:11px 28px;background:#287a66;color:#fff;font-weight:700;cursor:pointer">Continue</button>
      </div>`;
    overlay.querySelector("button")?.addEventListener("click", () => overlay.remove());
    document.body.appendChild(overlay);
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", showConfirmation, { once: true });
  } else {
    showConfirmation();
  }
})();
