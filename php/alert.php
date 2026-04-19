<?php
// alert.php — Universal SweetAlert2 Alert System
// Include this file in any page (after session_start())

if (isset($_SESSION['alert'])):
    $alert = $_SESSION['alert'];
    $type = $alert['type'] ?? 'info';
    $title = $alert['title'] ?? ucfirst($type);
    $message = $alert['message'] ?? '';
    $redirect = $alert['redirect'] ?? null;
    $showConfirm = $alert['showConfirm'] ?? false;
?>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            <?php if ($type === 'warning' && $showConfirm): ?>
                // ⚠️ Confirmation alert (used for "Are you sure?" type)
                Swal.fire({
                    icon: "warning",
                    title: "<?= addslashes($title) ?>",
                    html: `<p style="font-size:16px;margin-top:8px;"><?= addslashes($message) ?></p>`,
                    showCancelButton: true,
                    confirmButtonColor: "#49A47A",
                    cancelButtonColor: "#d33",
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
                    confirmButtonColor: "<?= $type === 'error' ? '#d33' : '#49A47A' ?>",
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
