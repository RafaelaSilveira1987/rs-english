document.addEventListener('DOMContentLoaded',()=>{
    const buttons=document.querySelectorAll('[data-tab-button]');
    const panels=document.querySelectorAll('[data-tab-panel]');

    buttons.forEach(button=>{
        button.addEventListener('click',()=>{
            const tab=button.dataset.tabButton;

            buttons.forEach(item=>{
                item.classList.toggle('btn-primary',item===button);
                item.classList.toggle('btn-secondary',item!==button);
            });

            panels.forEach(panel=>{
                panel.hidden=panel.dataset.tabPanel!==tab;
            });
        });
    });

    const textForm=document.getElementById('practice-form');
    const textInput=document.getElementById('message');
    const chat=document.getElementById('chat');
    const topicSelect=document.getElementById('conversation-topic');
    const styleSelect=document.getElementById('conversation-style');
    const correctionModeSelect=document.getElementById('correction-mode');
    const maxTurnsSelect=document.getElementById('conversation-max-turns');

    function conversationSettings(){
        return {
            topic:topicSelect?.value || 'daily_life',
            style:styleSelect?.value || 'guided',
            correction_mode:correctionModeSelect?.value || 'balanced',
            max_turns:Number(maxTurnsSelect?.value || 10)
        };
    }

    function addMessage(text,who){
        const box=document.createElement('div');
        box.className='list-card';
        box.style.maxWidth='82%';
        box.style.marginLeft=who==='student'?'auto':'0';
        box.style.background=who==='student'?'#eeefff':'#f8faff';

        const title=document.createElement('strong');
        title.textContent=who==='student'?'Você':'Emma';

        const p=document.createElement('p');
        p.style.whiteSpace='pre-line';
        p.textContent=text;

        box.append(title,p);
        chat.appendChild(box);
        chat.scrollTop=chat.scrollHeight;
    }

    textForm?.addEventListener('submit',async event=>{
        event.preventDefault();

        const message=textInput.value.trim();
        if(!message)return;

        addMessage(message,'student');
        textInput.value='';
        textInput.disabled=true;

        try{
            const response=await fetch('/api/web/teacher.php',{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({message,...conversationSettings()})
            });

            const data=await response.json();

            if(!response.ok){
                throw new Error(data.error || data.message || 'Erro ao conversar.');
            }

            addMessage(data.teacher_message || 'Sem resposta.','teacher');
        }catch(error){
            addMessage('Erro: '+error.message,'teacher');
        }finally{
            textInput.disabled=false;
            textInput.focus();
        }
    });

    const startButton=document.getElementById('start-recording');
    const stopButton=document.getElementById('stop-recording');
    const discardButton=document.getElementById('discard-recording');
    const sendButton=document.getElementById('send-recording');
    const preview=document.getElementById('recording-preview');
    const statusText=document.getElementById('voice-status-text');
    const timer=document.getElementById('voice-timer');
    const dot=document.getElementById('voice-dot');
    const result=document.getElementById('voice-result');
    const transcription=document.getElementById('student-transcription');
    const teacherResponse=document.getElementById('teacher-response');
    const teacherAudio=document.getElementById('teacher-audio');

    let recorder=null;
    let stream=null;
    let chunks=[];
    let blob=null;
    let startedAt=0;
    let durationSeconds=0;
    let timerHandle=null;

    function formatTime(seconds){
        const min=Math.floor(seconds/60).toString().padStart(2,'0');
        const sec=Math.floor(seconds%60).toString().padStart(2,'0');
        return `${min}:${sec}`;
    }

    function resetRecording(){
        blob=null;
        chunks=[];
        durationSeconds=0;
        preview.hidden=true;
        preview.removeAttribute('src');
        sendButton.disabled=true;
        discardButton.disabled=true;
        timer.textContent='00:00';
        statusText.textContent='Pronto para gravar';
        dot.classList.remove('recording');
    }

    function stopStream(){
        if(stream){
            stream.getTracks().forEach(track=>track.stop());
            stream=null;
        }
    }

    startButton?.addEventListener('click',async()=>{
        try{
            stream=await navigator.mediaDevices.getUserMedia({audio:true});

            const supported=[
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus'
            ];

            const mimeType=supported.find(type=>MediaRecorder.isTypeSupported(type)) || '';

            recorder=new MediaRecorder(stream,mimeType?{mimeType}:undefined);
            chunks=[];

            recorder.addEventListener('dataavailable',event=>{
                if(event.data.size>0)chunks.push(event.data);
            });

            recorder.addEventListener('stop',()=>{
                durationSeconds=(Date.now()-startedAt)/1000;
                blob=new Blob(chunks,{type:recorder.mimeType || 'audio/webm'});
                preview.src=URL.createObjectURL(blob);
                preview.hidden=false;
                sendButton.disabled=false;
                discardButton.disabled=false;
                statusText.textContent='Gravação pronta para enviar';
                dot.classList.remove('recording');
                stopStream();
            });

            recorder.start(250);
            startedAt=Date.now();

            timerHandle=setInterval(()=>{
                timer.textContent=formatTime((Date.now()-startedAt)/1000);
            },250);

            startButton.disabled=true;
            stopButton.disabled=false;
            discardButton.disabled=true;
            sendButton.disabled=true;
            statusText.textContent='Gravando...';
            dot.classList.add('recording');

        }catch(error){
            statusText.textContent='Não foi possível acessar o microfone: '+error.message;
        }
    });

    stopButton?.addEventListener('click',()=>{
        if(recorder && recorder.state!=='inactive'){
            recorder.stop();
        }

        clearInterval(timerHandle);
        timerHandle=null;
        startButton.disabled=false;
        stopButton.disabled=true;
    });

    discardButton?.addEventListener('click',()=>{
        if(preview.src)URL.revokeObjectURL(preview.src);
        resetRecording();
    });

    sendButton?.addEventListener('click',async()=>{
        if(!blob)return;

        sendButton.disabled=true;
        startButton.disabled=true;
        discardButton.disabled=true;
        statusText.textContent='Transcrevendo e preparando a resposta...';

        const formData=new FormData();
        const extension=blob.type.includes('ogg')?'ogg':'webm';

        formData.append('audio',blob,`voice-${Date.now()}.${extension}`);
        formData.append('duration_seconds',durationSeconds.toFixed(2));

        const settings=conversationSettings();
        formData.append('topic',settings.topic);
        formData.append('style',settings.style);
        formData.append('correction_mode',settings.correction_mode);
        formData.append('max_turns',String(settings.max_turns));

        try{
            const response=await fetch('/api/web/voice.php',{
                method:'POST',
                body:formData
            });

            const data=await response.json();

            if(!response.ok){
                throw new Error(data.error || 'Erro na conversa por áudio.');
            }

            transcription.textContent=data.show_transcription===false
                ? 'Transcrição ocultada nas preferências.'
                : data.transcription;

            teacherResponse.textContent=data.teacher_message;
            teacherAudio.src=data.teacher_audio_url;
            result.hidden=false;

            if(data.autoplay_audio){
                teacherAudio.play().catch(()=>{});
            }

            statusText.textContent='Resposta recebida';
        }catch(error){
            statusText.textContent='Erro: '+error.message;
        }finally{
            startButton.disabled=false;
            discardButton.disabled=false;
        }
    });

    window.addEventListener('beforeunload',stopStream);
});
