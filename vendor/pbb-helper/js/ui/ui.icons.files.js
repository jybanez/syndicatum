// Original Helper artwork: file-format symbols, not office-product logos.
const path = d => ({ tag: "path", attrs: { d } });
const file = nodes => ({ category: "files", viewBox: "0 0 24 24", nodes: [
  path("M14 2H5v20h14V7z M14 2v5h5"), ...nodes,
] });
export const FILE_ICONS = {
  "files.unknown": file([path("M10 12a2 2 0 1 1 3 1.7L12 15 M12 18h.01")]),
  "files.pdf": file([path("M8 18c4-5 5-9 3-9-2 0 1 7 5 8 3 1-2-4-8 1z")]),
  "files.word": file([path("M7 11l2 7 3-5 3 5 2-7")]),
  "files.excel": file([path("M8 11l8 8 M16 11l-8 8")]),
  "files.csv": file([path("M7 11h10v8H7z M7 15h10 M12 11v8")]),
  "files.presentation": file([path("M8 19v-8h5a3 3 0 0 1 0 6H8")]),
  "files.archive": file([path("M11 8h2 M11 11h2 M11 14h2 M11 17h2v3h-2z")]),
  "files.image": file([{ tag: "circle", attrs: { cx: 9, cy: 11, r: 1 } }, path("M7 19l3-4 2 2 2-4 3 6")]),
  "files.audio": file([path("M11 17v-6l5-1v6 M11 17c-4-2-4 4 0 1 M16 16c-4-2-4 4 0 1")]),
  "files.video": file([path("M10 11v8l6-4z")]),
  "files.code": file([path("M10 12l-3 3 3 3 M14 12l3 3-3 3")]),
  "files.json": file([path("M10 10c-3 0-1 5-3 5 2 0 0 5 3 5 M14 10c3 0 1 5 3 5-2 0 0 5-3 5")]),
  "files.text": file([path("M8 11h8 M8 15h8 M8 19h5")]),
};

const extensions = {
  pdf: "pdf", doc: "word", docx: "word", odt: "word", xls: "excel", xlsx: "excel", ods: "excel",
  csv: "csv", tsv: "csv", ppt: "presentation", pptx: "presentation", odp: "presentation",
  zip: "archive", rar: "archive", "7z": "archive", gz: "archive", tar: "archive",
  png: "image", jpg: "image", jpeg: "image", gif: "image", webp: "image", svg: "image", avif: "image",
  mp3: "audio", wav: "audio", ogg: "audio", flac: "audio", m4a: "audio",
  mp4: "video", webm: "video", mov: "video", avi: "video", mkv: "video",
  js: "code", ts: "code", html: "code", css: "code", py: "code", php: "code", sh: "code", sql: "code",
  json: "json", txt: "text", md: "text", log: "text", xml: "code", yaml: "code", yml: "code",
};
export function getFileIconName(filename = "", mimeType = "") {
  const clean = String(filename).split(/[?#]/)[0].split(/[\\/]/).pop();
  const extension = clean.includes(".") ? clean.split(".").pop().toLowerCase() : "";
  if (Object.hasOwn(extensions, extension)) return `files.${extensions[extension]}`;
  const mime = String(mimeType).toLowerCase().split(";")[0].trim();
  const exact = { "application/pdf": "pdf", "application/json": "json", "text/csv": "csv", "application/zip": "archive",
    "application/msword": "word", "application/vnd.openxmlformats-officedocument.wordprocessingml.document": "word",
    "application/vnd.ms-excel": "excel", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": "excel",
    "application/vnd.ms-powerpoint": "presentation", "application/vnd.openxmlformats-officedocument.presentationml.presentation": "presentation" };
  const families = { image: "image", audio: "audio", video: "video", text: "text" };
  return `files.${(Object.hasOwn(exact, mime) && exact[mime]) || (Object.hasOwn(families, mime.split("/")[0]) && families[mime.split("/")[0]]) || "unknown"}`;
}
