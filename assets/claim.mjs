const form = document.getElementById('claim-form');
const submit = document.getElementById('claim-submit');
const result = document.getElementById('claim-result');

function showMessage(message, isError = false) {
  result.hidden = false;
  result.classList.toggle('is-error', isError);
  result.textContent = message;
}

function showToken(payload) {
  result.hidden = false;
  result.classList.remove('is-error');
  result.innerHTML = '';

  const title = document.createElement('strong');
  title.textContent = `${payload.project_name} token`;

  const note = document.createElement('p');
  note.textContent = 'Store this token now. It is shown only once.';

  const token = document.createElement('textarea');
  token.className = 'ui-input claim-token';
  token.readOnly = true;
  token.value = payload.token;

  const actions = document.createElement('div');
  actions.className = 'claim-actions';

  const copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'ui-button ui-button-primary';
  copy.textContent = 'Copy token';
  copy.addEventListener('click', async () => {
    await navigator.clipboard.writeText(payload.token);
    copy.textContent = 'Copied';
  });

  actions.append(copy);
  result.append(title, note, token, actions);
  token.select();
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  submit.disabled = true;
  showMessage('Checking claim code...');

  const formData = new FormData(form);
  const body = {
    project_name: String(formData.get('project_name') || '').trim(),
    claim_code: String(formData.get('claim_code') || '').trim(),
  };

  try {
    const response = await fetch('/api/claim.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const payload = await response.json();
    if (!response.ok) {
      throw new Error(payload.message || 'Claim failed.');
    }
    showToken(payload.data);
  } catch (error) {
    showMessage(error.message || 'Claim failed.', true);
  } finally {
    submit.disabled = false;
  }
});
