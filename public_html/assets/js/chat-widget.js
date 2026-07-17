(function() {
    var isOpen = false;
    var isProcessing = false;

    function getSessionId() {
        var sid = localStorage.getItem('nc_session_id');
        if (!sid) {
            sid = 'nc_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
            localStorage.setItem('nc_session_id', sid);
        }
        return sid;
    }

    function html( str ) { var d = document.createElement('div'); d.textContent = str; return d.innerHTML; }

    function createWidget() {
        var toggle = document.createElement('button');
        toggle.id = 'nc-chat-toggle';
        toggle.setAttribute('aria-label', 'Toggle chat');
        toggle.innerHTML = '<svg class="chat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><svg class="close-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';

        var panel = document.createElement('div');
        panel.id = 'nc-chat-panel';
        panel.innerHTML =
            '<div class="nc-chat-header"><div class="nc-chat-header-avatar">&#x1F916;</div><div class="nc-chat-header-info"><div class="nc-chat-header-title">Prem Gas Solution AI</div><div class="nc-chat-header-sub">Ask me anything!</div></div></div><div class="nc-chat-messages" id="ncMessages"><div class="nc-greeting">Hi! I\'m the Prem Gas Solution AI assistant. Ask me about our products, services, or anything else!</div></div><div class="nc-chat-input-area"><input type="text" id="ncInput" class="nc-chat-input" placeholder="Type your message..." autocomplete="off"><button id="ncSend" class="nc-chat-send" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button></div>';

        document.body.appendChild(toggle);
        document.body.appendChild(panel);

        var messages = document.getElementById('ncMessages');
        var input = document.getElementById('ncInput');
        var sendBtn = document.getElementById('ncSend');
        var typingEl = null;

        function addMsg(text, role) {
            var div = document.createElement('div');
            div.className = 'nc-msg ' + role;
            div.innerHTML = '<div>' + html(text).replace(/\n/g, '<br>') + '</div><div class="nc-msg-time">' + (role === 'user' ? 'You' : 'AI') + ' &middot; just now</div>';
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }

        function addTyping() {
            typingEl = document.createElement('div');
            typingEl.className = 'nc-typing';
            typingEl.innerHTML = '<span></span><span></span><span></span>';
            messages.appendChild(typingEl);
            messages.scrollTop = messages.scrollHeight;
        }

        function removeTyping() {
            if (typingEl && typingEl.parentElement) { typingEl.parentElement.removeChild(typingEl); typingEl = null; }
        }

        function sendMessage(text) {
            if (isProcessing || !text.trim()) return;
            isProcessing = true;
            sendBtn.disabled = true;

            addMsg(text, 'user');
            input.value = '';
            var greeting = messages.querySelector('.nc-greeting');
            if (greeting) greeting.style.display = 'none';
            addTyping();

            fetch('/chat-api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, session_id: getSessionId() })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                removeTyping();
                if (data.success) {
                    addMsg(data.message, 'assistant');
                } else {
                    addMsg('Sorry, I had trouble responding. Please try again.', 'assistant');
                }
            })
            .catch(function() {
                removeTyping();
                addMsg('Connection error. Please check your internet and try again.', 'assistant');
            })
            .finally(function() {
                isProcessing = false;
                sendBtn.disabled = false;
                input.focus();
            });
        }

        toggle.addEventListener('click', function() {
            isOpen = !isOpen;
            toggle.classList.toggle('open', isOpen);
            panel.classList.toggle('open', isOpen);
            if (isOpen) input.focus();
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') sendMessage(input.value);
        });

        input.addEventListener('input', function() {
            sendBtn.disabled = !input.value.trim();
        });

        sendBtn.addEventListener('click', function() { sendMessage(input.value); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', createWidget);
    } else {
        createWidget();
    }
})();
