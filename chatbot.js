// ===== Chatbot: abrir/cerrar desde FAB, saludo, persistencia, envío a Groq =====

// Toggle del popup. Si pasas true, fuerza cerrar.
function toggleChatbot(forceClose) {
  const chatbotPopup = document.getElementById('chatbot-popup');
  const fab = document.getElementById('chat-fab');
  if (!chatbotPopup) return;

  const isHidden = chatbotPopup.style.display === 'none' || chatbotPopup.style.display === '';
  const shouldOpen = (typeof forceClose === 'boolean') ? !forceClose : isHidden;

  // visibilidad base (compatibilidad con tu lógica previa)
  chatbotPopup.style.display = shouldOpen ? 'block' : 'none';
  // clase para animación y estados
  chatbotPopup.classList.toggle('open', shouldOpen);

  // FAB visual
  if (fab) {
    fab.setAttribute('data-open', String(shouldOpen));
    fab.setAttribute('aria-label', shouldOpen ? 'Cerrar chat' : 'Abrir chat');
    fab.title = shouldOpen ? 'Cerrar chat' : 'Chatear';
  }

  // Mensaje de bienvenida
  if (shouldOpen && document.getElementById('chat-box')?.children.length === 0) {
    appendMessage('bot', "👋 Hola, soy el asistente virtual del Hotel Andino. ¿En qué puedo ayudarte hoy?");
  }

  // Focus al abrir y persistencia
  if (shouldOpen) setTimeout(() => document.getElementById('user-input')?.focus(), 0);
  try { localStorage.setItem('chatOpen', String(shouldOpen)); } catch(e) {}
}

// Enviar mensaje (👉 usa Groq)
async function sendMessage() {
  const inputField = document.getElementById('user-input');
  if (!inputField) return;
  const userInput = inputField.value.trim();
  if (userInput === '') return;

  appendMessage('user', userInput);
  inputField.value = '';

  try {
    const r = await fetch('send_to_groq.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: userInput })
    });

    if (r.ok) {
      const d = await r.json();
      appendMessage('bot', d.reply);
    } else {
      appendMessage('bot', '⚠️ Error en el servidor, intenta más tarde.');
    }
  } catch (e) {
    console.error(e);
    appendMessage('bot', '⚠️ Error de conexión.');
  }
}

// Agrega un mensaje al chat
function appendMessage(sender, message) {
  const chatBox = document.getElementById('chat-box');
  if (!chatBox) return;

  const div = document.createElement('div');
  div.className = sender === 'user' ? 'user-message' : 'bot-message';
  div.textContent = message;
  chatBox.appendChild(div);
  chatBox.scrollTop = chatBox.scrollHeight;
}

// ===== Listeners y restauración =====
document.addEventListener('DOMContentLoaded', () => {
  // Click en botón Enviar
  const sendBtn = document.getElementById('send-btn');
  if (sendBtn) sendBtn.addEventListener('click', sendMessage);

  // Enviar con Enter
  const userInput = document.getElementById('user-input');
  if (userInput) {
    userInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') sendMessage();
    });
  }

  // Click en FAB
  const fab = document.getElementById('chat-fab');
  if (fab) fab.addEventListener('click', () => toggleChatbot());

  // Restaurar estado (si el usuario lo dejó abierto)
  try {
    const saved = localStorage.getItem('chatOpen');
    if (saved === 'true') toggleChatbot(false); // abre
  } catch (e) {}
});

// Cerrar con ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const popup = document.getElementById('chatbot-popup');
    const isOpen = popup && !(popup.style.display === 'none' || popup.style.display === '');
    if (isOpen) toggleChatbot(true); // forzar cerrar
  }
});
