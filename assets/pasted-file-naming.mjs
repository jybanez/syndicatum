function projectFileNameKey(value) {
  return String(value || "").trim().toLocaleLowerCase();
}

export function pastedScreenshotTimestamp(date = new Date()) {
  const part = (value, length = 2) => String(value).padStart(length, "0");
  return `${date.getFullYear()}${part(date.getMonth() + 1)}${part(date.getDate())}`
    + `-${part(date.getHours())}${part(date.getMinutes())}${part(date.getSeconds())}`
    + `-${part(date.getMilliseconds(), 3)}`;
}

export function preparePastedProjectFile(file, usedNames, timestamp = new Date()) {
  const originalName = String(file?.name || "").trim();
  const isGenericScreenshot = originalName === "image.png"
    && String(file?.type || "").toLocaleLowerCase() === "image/png";
  if (!isGenericScreenshot) {
    if (originalName) usedNames.add(projectFileNameKey(originalName));
    return file;
  }

  const base = `screenshot-${pastedScreenshotTimestamp(timestamp)}`;
  let candidate = `${base}.png`;
  let suffix = 2;
  while (usedNames.has(projectFileNameKey(candidate))) {
    candidate = `${base}-${suffix}.png`;
    suffix += 1;
  }
  usedNames.add(projectFileNameKey(candidate));
  return new File([file], candidate, {
    type: file.type || "image/png",
    lastModified: Number(file.lastModified || Date.now()),
  });
}
