
  </main><!-- /admin-content -->
</div><!-- /admin-main -->

</div><!-- /admin-wrap -->

<script>
// Sidebar toggle para móvil
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar       = document.getElementById('adminSidebar');

if (window.innerWidth <= 768) {
  sidebarToggle.style.display = 'flex';
}

sidebarToggle?.addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

// Cerrar sidebar al clickar fuera (móvil)
document.addEventListener('click', (e) => {
  if (window.innerWidth > 768) return;
  if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
    sidebar.classList.remove('open');
  }
});

window.addEventListener('resize', () => {
  sidebarToggle.style.display = window.innerWidth <= 768 ? 'flex' : 'none';
});
</script>
<script src="/Blue/assets/js/confirm-modal.js"></script>
<script src="/Blue/assets/js/table-scroll.js"></script>
</body>
</html>
