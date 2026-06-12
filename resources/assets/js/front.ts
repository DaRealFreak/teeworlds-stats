// Dark-admin theme interactions, vanilla (no jQuery). Only the behaviours whose
// markup actually exists are kept: the sidebar toggle, the footer height fix and
// the navbar dropdown fade. (The template's form-validate, material-input and
// search-popup hooks are not used by any view, so they are intentionally absent.)
document.addEventListener('DOMContentLoaded', function () {
    const pageContent = document.querySelector<HTMLElement>('.page-content');

    // ------------------------------------------------------- //
    // Footer: keep page padding clear of the absolutely-positioned footer
    // ------------------------------------------------------ //
    function adjustFooter() {
        const footerBlock = document.querySelector<HTMLElement>('.footer__block');
        if (footerBlock && pageContent) {
            pageContent.style.paddingBottom = footerBlock.offsetHeight + 'px';
        }
    }

    adjustFooter();
    window.addEventListener('resize', adjustFooter);

    // ------------------------------------------------------- //
    // Sidebar toggle
    // ------------------------------------------------------ //
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function () {
            const collapsed = sidebarToggle.classList.toggle('active');

            document.getElementById('sidebar')?.classList.toggle('shrinked');
            pageContent?.classList.toggle('active');
            adjustFooter();

            const brandSmall = document.querySelector('.navbar-brand .brand-sm');
            const brandBig = document.querySelector('.navbar-brand .brand-big');
            const icon = sidebarToggle.querySelector('i');

            brandSmall?.classList.toggle('visible', collapsed);
            brandBig?.classList.toggle('visible', !collapsed);
            if (icon) {
                icon.className = collapsed ? 'fa fa-long-arrow-right' : 'fa fa-long-arrow-left';
            }
        });
    }

    // ------------------------------------------------------- //
    // Navbar dropdown fade — the theme animates via an .active class that
    // Bootstrap's own show/hide events drive
    // ------------------------------------------------------ //
    document.querySelectorAll('.dropdown').forEach(function (dropdown) {
        dropdown.addEventListener('show.bs.dropdown', function () {
            dropdown.querySelector('.dropdown-menu')?.classList.add('active');
        });
        dropdown.addEventListener('hide.bs.dropdown', function () {
            dropdown.querySelector('.dropdown-menu')?.classList.remove('active');
        });
    });
});

export {};
