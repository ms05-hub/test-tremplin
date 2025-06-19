document.addEventListener('DOMContentLoaded', () => {
  const addBtn        = document.querySelector('.ajouter-dispo');
  const listContainer = document.querySelector('.liste-dispos');
  const form          = document.querySelector('.contact-form');

  addBtn.addEventListener('click', () => {
    const jourSelect   = document.getElementById('jour');
    const heureSelect  = document.getElementById('heure');
    const minuteSelect = document.getElementById('minute');

    const jourText   = jourSelect.options[jourSelect.selectedIndex].text;   // "Lundi"
    const heureText  = heureSelect.options[heureSelect.selectedIndex].text; // "7h"
    const minuteText = minuteSelect.options[minuteSelect.selectedIndex].text; // "15"

    const text = `${jourText} à ${heureText}${minuteText}`; // -> "Lundi à 7h15"

    const item = document.createElement('div');
    item.className = 'dispo-item';
    item.textContent = text;

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.addEventListener('click', () => {
      hiddenInput.remove();
      item.remove();
    });

    const hiddenInput = document.createElement('input');
    hiddenInput.type  = 'hidden';
    hiddenInput.name  = 'disponibilites[]';
    hiddenInput.value = `${jourSelect.value}|${heureSelect.value}|${minuteSelect.value}`;

    item.appendChild(removeBtn);
    listContainer.appendChild(item);
    form.appendChild(hiddenInput);
  });
});
