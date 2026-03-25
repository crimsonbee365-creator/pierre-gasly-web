<?php
/**
 * PIERRE GASLY - Admin Footer
 * Common footer for all admin pages
 */

?>
    </div> <!-- Close main-content -->

    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Confirm delete actions
        document.querySelectorAll('[data-confirm]').forEach(function(element) {
            element.addEventListener('click', function(e) {
                if (!confirm(this.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });
    </script>

    <!-- Added JavaScript File -->
    <script src="assets/js/validation.js"></script>

</body>
</html>
