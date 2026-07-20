(() => {
  const csrf = document.body.dataset.csrf;
  let dragged = null;

  document.querySelectorAll('[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  document.querySelectorAll('.participant-card[draggable="true"]').forEach((card) => {
    card.addEventListener('dragstart', () => {
      dragged = card;
      card.classList.add('is-dragging');
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('is-dragging');
      dragged = null;
    });
  });

  document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    zone.addEventListener('dragover', (event) => {
      event.preventDefault();
      zone.classList.add('is-over');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
    zone.addEventListener('drop', async (event) => {
      event.preventDefault();
      zone.classList.remove('is-over');
      if (!dragged) return;

      const targetLeague = zone.closest('[data-league-id]');
      const sourceLeague = dragged.closest('[data-league-id]');
      if (!targetLeague || targetLeague === sourceLeague) return;

      const body = new FormData();
      body.set('action', 'move_participant');
      body.set('csrf_token', csrf);
      body.set('participant_id', dragged.dataset.participantId);
      body.set('league_id', targetLeague.dataset.leagueId);

      try {
        const response = await fetch('/admin.php', { method: 'POST', body, credentials: 'same-origin' });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Verschieben fehlgeschlagen.');
        zone.appendChild(dragged);
        window.location.reload();
      } catch (error) {
        window.alert(error.message);
      }
    });
  });
})();

