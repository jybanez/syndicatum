import { uiLoader } from "../vendor/pbb-helper/dist/helpers.ui.bundle.min.js?v=0.21.207";

const steps = [
  { id: "ownership", title: "Ownership", subtitle: "Prove control" },
  { id: "system", title: "System check", subtitle: "Review environment" },
  { id: "database", title: "Database", subtitle: "Plan connection" },
  { id: "administrator", title: "Administrator", subtitle: "Plan first account" },
  { id: "review", title: "Review & install", subtitle: "Confirm scope" },
  { id: "completion", title: "Completion", subtitle: "Confirm result" },
];

const content = {
  ownership: {
    title: "Prove control of this installation",
    description: "The connected installer will require short-lived ownership proof before it accepts database details or creates the first administrator.",
    fields: [["Public Syndicatum URL", "Not yet available"], ["Ownership proof", "Not yet available"]],
  },
  system: {
    title: "Check the installation environment",
    description: "The installer will verify the supported runtime, writable storage, HTTPS origin, and database reachability before it changes anything.",
    rows: [["Runtime baseline", "Not yet checked"], ["Storage permissions", "Not yet checked"], ["Public HTTPS origin", "Not yet checked"], ["Database reachability", "Not yet checked"]],
  },
  database: {
    title: "Connect the Syndicatum database",
    description: "Connection fields will be collected here and validated without exposing the password after submission.",
    fields: [["Database host", "Not yet available"], ["Database port", "Not yet available"], ["Database name", "Not yet available"], ["Database user", "Not yet available"]],
  },
  administrator: {
    title: "Create the first administrator",
    description: "The first human administrator will own installation settings. Password and external sign-in rules will be enforced by the connected setup service.",
    fields: [["Display name", "Not yet available"], ["Email address", "Not yet available"], ["Password", "Not yet available"]],
  },
  review: {
    title: "Review before installation",
    description: "A final summary, secret-storage destination, and explicit confirmation will appear here once the installer contracts are connected.",
    rows: [["Environment", "Not verified"], ["Database", "Not configured"], ["Administrator", "Not configured"], ["Installation action", "Not yet available"]],
  },
  completion: {
    title: "Installation has not run",
    description: "This completion screen is a preview of the future result summary. No installation state, administrator, or database was created.",
    rows: [["Installation", "Not started"], ["Health verification", "Not available"], ["Administrator sign-in", "Not available"], ["Audit record", "Not created"]],
  },
};

let currentIndex = 0;
let stepper = null;

function renderDetails(panel, rows) {
  const list = document.createElement("dl");
  list.className = "setup-details";
  rows.forEach(([label, value]) => {
    const term = document.createElement("dt"); term.textContent = label;
    const detail = document.createElement("dd"); detail.textContent = value;
    list.append(term, detail);
  });
  panel.appendChild(list);
}

function renderFields(panel, fields) {
  const grid = document.createElement("div");
  grid.className = "setup-fields";
  fields.forEach(([labelText, placeholder]) => {
    const label = document.createElement("label");
    label.textContent = labelText;
    const input = document.createElement("input");
    input.className = "ui-input";
    input.type = labelText === "Password" ? "password" : "text";
    input.placeholder = placeholder;
    input.disabled = true;
    label.appendChild(input);
    grid.appendChild(label);
  });
  panel.appendChild(grid);
}

function renderStep({ focusStepper = false } = {}) {
  const step = steps[currentIndex];
  const spec = content[step.id];
  stepper.setCurrentStep(step.id);
  const host = document.getElementById("setup-content");
  host.replaceChildren();
  const panel = document.createElement("section");
  panel.className = "setup-panel";
  const status = document.createElement("span");
  status.className = "ui-badge setup-unavailable";
  status.textContent = "Not yet available";
  const title = document.createElement("h2"); title.textContent = spec.title;
  const description = document.createElement("p"); description.textContent = spec.description;
  panel.append(status, title, description);
  if (spec.rows) renderDetails(panel, spec.rows);
  if (spec.fields) renderFields(panel, spec.fields);
  host.appendChild(panel);

  const back = document.getElementById("setup-back");
  const next = document.getElementById("setup-next");
  const reviewIndex = steps.findIndex((item) => item.id === "review");
  back.disabled = currentIndex === 0;
  next.textContent = currentIndex === reviewIndex ? "Begin installation" : (currentIndex === steps.length - 1 ? "Finish" : "Next");
  next.disabled = currentIndex >= reviewIndex;
  if (next.disabled) next.setAttribute("aria-describedby", "setup-boundary");
  else next.removeAttribute("aria-describedby");
  if (focusStepper) {
    document.querySelectorAll("#setup-stepper .ui-stepper-trigger")[currentIndex]?.focus({ preventScroll: true });
  }
}

async function bootstrap() {
  const options = { css: false };
  await uiLoader.load("ui.stepper", options);
  const createStepper = await uiLoader.get("ui.stepper", options);
  stepper = createStepper(document.getElementById("setup-stepper"), steps, {
    ariaLabel: "Installation steps",
    currentStepId: steps[0].id,
    clickable: true,
    onStepClick(step) {
      const nextIndex = steps.findIndex((item) => item.id === step.id);
      if (nextIndex >= 0) { currentIndex = nextIndex; renderStep({ focusStepper: true }); }
    },
  });
  document.getElementById("setup-back").addEventListener("click", () => {
    if (currentIndex > 0) { currentIndex -= 1; renderStep(); }
  });
  document.getElementById("setup-next").addEventListener("click", () => {
    if (currentIndex < steps.length - 1) { currentIndex += 1; renderStep(); }
  });
  renderStep();
}

bootstrap().catch((error) => {
  const host = document.getElementById("setup-content");
  host.textContent = `Setup preview could not start: ${error.message}`;
});
