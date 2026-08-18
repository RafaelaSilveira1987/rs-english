document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('[data-progress]').forEach(el=>{
        const value=Math.max(0,Math.min(100,Number(el.dataset.progress||0)));
        el.setAttribute('aria-valuenow',String(Math.round(value)));
        requestAnimationFrame(()=>el.style.width=value+'%');
    });

    const menu=document.querySelector('[data-mobile-menu]');
    const overlay=document.querySelector('[data-sidebar-overlay]');
    const closeSidebar=()=>document.body.classList.remove('sidebar-open');

    menu?.addEventListener('click',()=>document.body.classList.toggle('sidebar-open'));
    overlay?.addEventListener('click',closeSidebar);
    document.querySelectorAll('.sidebar a').forEach(link=>link.addEventListener('click',closeSidebar));
    document.addEventListener('keydown',event=>{if(event.key==='Escape')closeSidebar();});

    document.querySelectorAll('[data-confirm]').forEach(element=>{
        element.addEventListener('click',event=>{
            if(!window.confirm(element.dataset.confirm||'Confirmar esta ação?')) event.preventDefault();
        });
    });
});
