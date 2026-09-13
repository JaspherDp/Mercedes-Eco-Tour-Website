<?php
// alert.php — Universal SweetAlert2 Alert System
// Include this file in any page after AppSessionStart().

if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $type = $alert['type'] ?? 'info';
    $title = $alert['title'] ?? ucfirst($type);
    $message = $alert['message'] ?? '';
    $redirect = $alert['redirect'] ?? null;
    $showConfirm = $alert['showConfirm'] ?? false;
    $retryEmailBookingId = max(0, (int)($alert['retry_email_booking_id'] ?? 0));
    $retryEmailReturnTab = in_array((string)($alert['retry_email_return_tab'] ?? ''), ['all', 'accepted'], true)
        ? (string)$alert['retry_email_return_tab']
        : 'accepted';
?>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            <?php if ($retryEmailBookingId > 0): ?>
                Swal.fire({
                    icon: "warning",
                    title: <?= json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                    html: `<p style="font-size:16px;margin-top:8px;"><?= addslashes($message) ?></p>`,
                    showCancelButton: true,
                    confirmButtonColor: "#176b55",
                    cancelButtonColor: "#687b75",
                    confirmButtonText: "Retry Sending Email",
                    cancelButtonText: "Close",
                    focusCancel: false
                }).then((result) => {
                    if (!result.isConfirmed) return;

                    Swal.fire({
                        title: "Sending Confirmation Email",
                        text: "Please wait while the confirmation email is sent.",
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => Swal.showLoading()
                    });

                    const form = document.createElement("form");
                    form.method = "POST";
                    form.action = "adbookings.php";
                    const values = {
                        id: <?= json_encode((string)$retryEmailBookingId) ?>,
                        action: "retry_confirmation_email",
                        return_tab: <?= json_encode($retryEmailReturnTab) ?>
                    };
                    Object.entries(values).forEach(([name, value]) => {
                        const input = document.createElement("input");
                        input.type = "hidden";
                        input.name = name;
                        input.value = value;
                        form.appendChild(input);
                    });
                    document.body.appendChild(form);
                    form.submit();
                });
            <?php elseif ($type === 'warning' && $showConfirm): ?>
                // ⚠️ Confirmation alert (used for "Are you sure?" type)
                Swal.fire({
                    icon: "warning",
                    title: "<?= addslashes($title) ?>",
                    html: `<p style="font-size:16px;margin-top:8px;"><?= addslashes($message) ?></p>`,
                    showCancelButton: true,
                    confirmButtonColor: "#176b55",
                    cancelButtonColor: "#687b75",
                    confirmButtonText: "Yes, proceed",
                    cancelButtonText: "Cancel",
                    focusCancel: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        <?php if ($redirect): ?>
                            window.location.href = "<?= addslashes($redirect) ?>";
                        <?php endif; ?>
                    }
                });
            <?php else: ?>
                // ✅ Standard alerts (success, error, info)
                Swal.fire({
                    icon: "<?= addslashes($type) ?>",
                    title: "<?= addslashes($title) ?>",
                    html: `<p style="font-size:16px;margin-top:8px;"><?= addslashes($message) ?></p>`,
                    confirmButtonColor: "<?= $type === 'error' ? '#c53f4a' : '#176b55' ?>",
                    confirmButtonText: "OK"
                }).then(() => {
                    <?php if ($redirect): ?>
                        window.location.href = "<?= addslashes($redirect) ?>";
                    <?php endif; ?>
                });
            <?php endif; ?>
        });
    </script>
<?php
    unset($_SESSION['alert']); // clear after one-time use
endif;
?>
