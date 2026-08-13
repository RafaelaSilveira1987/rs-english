document.addEventListener('DOMContentLoaded',()=>{
    document.querySelectorAll('[data-progress]').forEach(el=>{
        const value=Math.max(0,Math.min(100,Number(el.dataset.progress||0)));
        requestAnimationFrame(()=>el.style.width=value+'%');
    });

    const menu=document.querySelector('[data-mobile-menu]');

    if(menu){
        menu.addEventListener('click',()=>{
            document.body.classList.toggle('sidebar-open');
        });
    }

    document.addEventListener('click',event=>{
        if(
            document.body.classList.contains('sidebar-open') &&
            !event.target.closest('.sidebar') &&
            !event.target.closest('[data-mobile-menu]')
        ){
            document.body.classList.remove('sidebar-open');
        }
    });
});
