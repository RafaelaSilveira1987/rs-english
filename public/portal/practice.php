<?php
declare(strict_types=1);

require_once __DIR__.'/../../src/auth.php';

require_student();

$pageTitle='Praticar com Emma';
require __DIR__.'/../../templates/header.php';
?>

<section class="panel">
<div id="chat" style="height:440px;overflow:auto;padding:8px 0"></div>

<form id="practice-form" style="display:flex;gap:10px;margin-top:15px">
    <input id="message" autocomplete="off" placeholder="Escreva em inglês..." required>
    <button class="btn btn-primary" style="max-width:120px">Enviar</button>
</form>
</section>

<script>
const chat=document.getElementById('chat');
const form=document.getElementById('practice-form');
const input=document.getElementById('message');

function addMessage(text,who){
    const box=document.createElement('div');

    box.className='list-card';
    box.style.maxWidth='80%';
    box.style.marginLeft=who==='student'?'auto':'0';
    box.style.background=who==='student'?'#eeefff':'#f8faff';

    box.innerHTML='<strong>'+(who==='student'?'Você':'Emma')+'</strong><p style="white-space:pre-line"></p>';
    box.querySelector('p').textContent=text;

    chat.appendChild(box);
    chat.scrollTop=chat.scrollHeight;
}

form.addEventListener('submit',async e=>{
    e.preventDefault();

    const message=input.value.trim();
    if(!message)return;

    addMessage(message,'student');

    input.value='';
    input.disabled=true;

    try{
        const response=await fetch('/api/web/teacher.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({message})
        });

        const data=await response.json();

        if(!response.ok){
            throw new Error(data.error || data.message || 'Erro ao conversar.');
        }

        addMessage(data.teacher_message || 'Sem resposta.','teacher');
    }catch(err){
        addMessage('Erro: '+err.message,'teacher');
    }finally{
        input.disabled=false;
        input.focus();
    }
});
</script>

<?php require __DIR__.'/../../templates/footer.php'; ?>
