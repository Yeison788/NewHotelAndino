// admin/js/room.js

document.addEventListener('DOMContentLoaded', () => {
  const targetUrl = `room.php${window.location.search || ''}`;

  const showToast = (message, variant = 'success') => {
    const container = document.createElement('div');
    container.className = `alert alert-${variant} fade position-fixed top-0 end-0 m-3 shadow`;
    container.style.zIndex = '1080';
    container.textContent = message;
    document.body.appendChild(container);
    setTimeout(() => container.classList.add('show'), 10);
    setTimeout(() => {
      container.classList.remove('show');
      setTimeout(() => container.remove(), 300);
    }, 2500);
  };

  document.querySelectorAll('.js-status-form').forEach((form) => {
    const roomId = form.getAttribute('data-room');

    form.querySelectorAll('button[name="status"]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();

        const status = btn.value;
        const data = new URLSearchParams();
        data.append('change_status', '1');
        data.append('room_id', roomId);
        data.append('status', status);

        fetch(targetUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: data.toString()
        })
          .then(async (res) => {
            const isJson = res.headers.get('content-type')?.includes('application/json');
            const payload = isJson ? await res.json() : { ok: res.ok };
            if (!res.ok || !payload.ok) {
              throw new Error(payload?.error || 'Error al actualizar estado');
            }

            const badge = document.querySelector(`#room-${roomId} .badge`);
            if (badge) {
              const classMap = {
                'Disponible': 'bg-success text-white',
                'Reservada': 'bg-warning text-dark',
                'Limpieza': 'bg-info text-dark',
                'Ocupada': 'bg-danger text-white'
              };
              badge.textContent = status;
              badge.className = 'badge ' + (classMap[status] || 'bg-secondary text-white');
            }
            showToast('Estado actualizado');
          })
          .catch((err) => {
            console.error(err);
            alert('No se pudo actualizar el estado. Reintenta.');
          });
      });
    });
  });

  document.querySelectorAll('.js-room-config-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      formData.append('edit_room', '1');

      fetch(targetUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((payload) => {
          if (!payload?.ok) {
            throw new Error('Respuesta inválida');
          }
          const { room_id: roomId, type, bedding } = payload;
          const label = document.querySelector(`#room-${roomId} .room-type-label`);
          if (label) {
            label.textContent = `${type} · ${bedding}`;
          }
          showToast('Configuración actualizada');
        })
        .catch((error) => {
          console.error(error);
          alert('No se pudieron guardar los cambios.');
        });
    });
  });

  document.querySelectorAll('.js-stay-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const formData = new FormData(form);
      formData.append('save_stay', '1');

      fetch(targetUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
      })
        .then((res) => res.json())
        .then((payload) => {
          if (!payload?.ok) {
            throw new Error('Respuesta inválida');
          }
          const { room_id: roomId, summary_html: summaryHtml, room } = payload;
          const summary = document.querySelector(`#room-${roomId} .guest-summary-content`);
          if (summary) {
            summary.innerHTML = summaryHtml;
          }
          if (room?.type && room?.bedding) {
            const label = document.querySelector(`#room-${roomId} .room-type-label`);
            if (label) {
              label.textContent = `${room.type} · ${room.bedding}`;
            }
          }
          const modalElement = form.closest('.modal');
          if (modalElement) {
            const instance = bootstrap.Modal.getInstance(modalElement);
            instance?.hide();
          }
          showToast('Datos del huésped actualizados');
        })
        .catch((error) => {
          console.error(error);
          alert('No se pudieron guardar los datos del huésped.');
        });
    });
  });
});
