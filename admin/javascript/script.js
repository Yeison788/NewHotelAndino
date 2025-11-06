const navButtons = document.querySelectorAll('.sidenav .pagebtn');
const frames = Array.from(document.querySelectorAll('.frames'));
const submenuTriggers = document.querySelectorAll('.submenu-trigger');

function activateButton(button) {
  const frameIndex = parseInt(button.dataset.frame, 10);

  if (Number.isNaN(frameIndex) || !frames[frameIndex]) {
    return;
  }

  navButtons.forEach((btn) => btn.classList.remove('active'));
  submenuTriggers.forEach((trigger) => trigger.classList.remove('active'));

  button.classList.add('active');

  frames.forEach((frame, index) => {
    frame.classList.toggle('active', index === frameIndex);
  });

  const desiredSrc = button.dataset.src;
  if (desiredSrc) {
    const frame = frames[frameIndex];
    if (frame.getAttribute('src') !== desiredSrc) {
      frame.setAttribute('src', desiredSrc);
    }
  }

  const parentSubmenu = button.closest('.has-submenu');
  if (parentSubmenu) {
    const trigger = parentSubmenu.querySelector('.submenu-trigger');
    if (trigger) {
      trigger.classList.add('active');
    }
  }
}

navButtons.forEach((button) => {
  button.addEventListener('click', () => activateButton(button));
});

document.addEventListener('DOMContentLoaded', () => {
  const initialButton = Array.from(navButtons).find((btn) => btn.classList.contains('active'));
  if (initialButton) {
    activateButton(initialButton);
  }

  const profileMenu = document.querySelector('.profile-menu');
  const profileTrigger = document.querySelector('.profile-trigger');
  if (profileMenu && profileTrigger) {
    const closeMenu = () => {
      profileMenu.classList.remove('is-open');
      profileTrigger.setAttribute('aria-expanded', 'false');
    };

    profileTrigger.addEventListener('click', (event) => {
      event.stopPropagation();
      const isOpen = profileMenu.classList.toggle('is-open');
      profileTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
      if (!profileMenu.contains(event.target)) {
        closeMenu();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeMenu();
      }
    });
  }
});
