// USAFA Alabama Falcons - Photo Gallery Backend
// ─────────────────────────────────────────────
// Setup:
//   1. Go to script.google.com and create a new project.
//   2. Paste this entire file into the editor and save.
//   3. Click Deploy → New deployment → Web app.
//      Execute as: Me | Who has access: Anyone
//   4. Authorize when prompted, then copy the Web app URL.
//   5. Paste that URL into gallery.html as APPS_SCRIPT_URL.
//
// Adding new events:
//   Create a subfolder inside "Club Photos" in Google Drive
//   (e.g. "2027 BCT Sendoff") and upload photos. No code changes needed.
//
// Redeploying after edits:
//   Use Deploy → Manage deployments → Edit (keep the same deployment)
//   so the URL stays the same and gallery.html does not need updating.

const PHOTOS_FOLDER_NAME = 'Club Photos';

function doGet(e) {
  const action   = (e.parameter.action   || '').trim();
  const folderId = (e.parameter.folderId || '').trim();
  let result;
  try {
    if (action === 'folders') {
      result = listEventFolders();
    } else if (action === 'photos' && folderId) {
      result = listPhotos(folderId);
    } else {
      result = { error: 'Unknown action. Use ?action=folders or ?action=photos&folderId=ID' };
    }
  } catch (err) {
    result = { error: err.message };
  }
  return ContentService
    .createTextOutput(JSON.stringify(result))
    .setMimeType(ContentService.MimeType.JSON);
}

function listEventFolders() {
  const iter = DriveApp.getFoldersByName(PHOTOS_FOLDER_NAME);
  if (!iter.hasNext()) return { files: [] };
  const parent = iter.next();
  const subIter = parent.getFolders();
  const folders = [];
  while (subIter.hasNext()) {
    const f = subIter.next();
    folders.push({ id: f.getId(), name: f.getName(), created: f.getDateCreated().getTime() });
  }
  // Newest first by Drive folder creation date
  folders.sort((a, b) => b.created - a.created);
  return { files: folders };
}

function listPhotos(folderId) {
  // Verify the requested folder is a direct child of the Club Photos folder
  const parentIter = DriveApp.getFoldersByName(PHOTOS_FOLDER_NAME);
  if (!parentIter.hasNext()) return { error: 'Photos folder not found' };
  const parentId = parentIter.next().getId();

  let folder;
  try { folder = DriveApp.getFolderById(folderId); } catch(e) { return { error: 'Invalid folder' }; }

  const parents = folder.getParents();
  let isChild = false;
  while (parents.hasNext()) { if (parents.next().getId() === parentId) { isChild = true; break; } }
  if (!isChild) return { error: 'Invalid folder' };

  const fileIter = folder.getFiles();
  const photos   = [];
  while (fileIter.hasNext()) {
    const f = fileIter.next();
    if (f.getMimeType().startsWith('image/')) {
      photos.push({ id: f.getId(), name: f.getName() });
    }
  }
  photos.sort((a, b) => a.name.localeCompare(b.name));
  return { files: photos };
}
