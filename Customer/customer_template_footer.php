<?php
// customer_template_footer.php

// Closing the Main Content and Grid
?>
</main>
</div>

<script>
    // Function to toggle the sidebar state
    function toggleSidebar() {
        const body = document.body;
        const isCollapsed = body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebarState', isCollapsed ? 'collapsed' : 'open');
    }

    // Load saved state on page load and handle star visuals
    window.onload = function() {
        lucide.createIcons();
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed') {
            document.body.classList.add('sidebar-collapsed');
        }

        // Star rating visual selector logic (FIXED)
        const starInputs = document.querySelectorAll('input[name="rating_value"]');

        function updateStarVisuals(selectedValue) {
            document.querySelectorAll('label[for^="star"]').forEach(label => {
                const starIndex = parseInt(label.getAttribute('for').replace('star', ''));
                const icon = label.querySelector('i');

                // Always reset before applying new color
                icon.classList.remove('text-gray-300', 'text-yellow-500');
                icon.classList.add('fill-current');

                if (starIndex <= selectedValue) {
                    icon.classList.add('text-yellow-500');
                } else {
                    icon.classList.add('text-gray-300');
                }
            });
        }

        // Event Listener for clicks/changes
        starInputs.forEach(input => {
            input.addEventListener('change', () => {
                const selectedValue = parseInt(input.value);
                updateStarVisuals(selectedValue);
            });
        });

        // Handle hover effect (UX improvement)
        document.querySelectorAll('label[for^="star"]').forEach(label => {
            label.addEventListener('mouseover', () => {
                const hoverValue = parseInt(label.getAttribute('for').replace('star', ''));
                updateStarVisuals(hoverValue);
            });
            label.addEventListener('mouseout', () => {
                // Reset to the currently checked value
                const checkedStar = document.querySelector('input[name="rating_value"]:checked');
                if (checkedStar) {
                    updateStarVisuals(parseInt(checkedStar.value));
                } else {
                    // If nothing is checked, reset to blank
                    updateStarVisuals(0);
                }
            });
        });


        // Initial render state for stars (only run if we are on the rate view)
        if (document.querySelector('input[name="action"][value="rate_submit"]')) {
            const defaultStar = document.getElementById('star5');
            // Check if the defaultStar element exists before trying to access its properties
            if (defaultStar) defaultStar.checked = true;
            if (defaultStar) defaultStar.dispatchEvent(new Event('change'));
        }
    };
</script>
</body>
</html>