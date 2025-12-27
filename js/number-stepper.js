document.addEventListener('DOMContentLoaded', () => {
  const inputs = Array.from(document.querySelectorAll('input[type="number"]'));
  inputs.forEach((input) => {
    if (input.dataset.stepper === 'false') return;
    if (input.closest('.number-stepper')) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'number-stepper';
    const minus = document.createElement('button');
    minus.type = 'button';
    minus.className = 'stepper-btn';
    minus.setAttribute('aria-label', 'Decrease value');
    minus.textContent = '-';
    const plus = document.createElement('button');
    plus.type = 'button';
    plus.className = 'stepper-btn';
    plus.setAttribute('aria-label', 'Increase value');
    plus.textContent = '+';
    const parent = input.parentNode;
    if (!parent) return;
    parent.insertBefore(wrapper, input);
    input.classList.add('stepper-input');
    wrapper.appendChild(minus);
    wrapper.appendChild(input);
    wrapper.appendChild(plus);

    const getStep = () => {
      const stepAttr = input.getAttribute('step');
      const stepValue = stepAttr ? parseFloat(stepAttr) : 1;
      return Number.isFinite(stepValue) && stepValue > 0 ? stepValue : 1;
    };

    const clampValue = (value) => {
      let next = value;
      const minAttr = input.getAttribute('min');
      const maxAttr = input.getAttribute('max');
      if (minAttr !== null && minAttr !== '') {
        const minValue = parseFloat(minAttr);
        if (Number.isFinite(minValue)) {
          next = Math.max(next, minValue);
        }
      }
      if (maxAttr !== null && maxAttr !== '') {
        const maxValue = parseFloat(maxAttr);
        if (Number.isFinite(maxValue)) {
          next = Math.min(next, maxValue);
        }
      }
      return next;
    };

    const updateValue = (delta) => {
      const current = parseFloat(input.value || '0');
      const base = Number.isFinite(current) ? current : 0;
      const step = getStep();
      const next = clampValue(base + delta * step);
      input.value = String(next);
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    let holdTimer = null;
    let holdInterval = null;
    let holdStart = 0;

    const clearHold = () => {
      if (holdTimer) {
        clearTimeout(holdTimer);
        holdTimer = null;
      }
      if (holdInterval) {
        clearInterval(holdInterval);
        holdInterval = null;
      }
    };

    const startHold = (direction) => {
      clearHold();
      holdStart = Date.now();
      holdTimer = setTimeout(() => {
        holdInterval = setInterval(() => {
          updateValue(direction);
          const elapsed = Date.now() - holdStart;
          if (elapsed > 2000 && holdInterval) {
            clearInterval(holdInterval);
            holdInterval = setInterval(() => updateValue(direction), 60);
          }
        }, 120);
      }, 400);
    };

    const bindHold = (button, direction) => {
      button.addEventListener('click', () => updateValue(direction));
      button.addEventListener('mousedown', () => startHold(direction));
      button.addEventListener('touchstart', (event) => {
        event.preventDefault();
        startHold(direction);
      }, { passive: false });
      ['mouseup', 'mouseleave', 'touchend', 'touchcancel'].forEach((evt) => {
        button.addEventListener(evt, clearHold);
      });
    };

    bindHold(minus, -1);
    bindHold(plus, 1);
  });
});

