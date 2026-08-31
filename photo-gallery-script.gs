// ============================================================
//  USAFA Parents Club - Community Photo Gallery
//  Google Apps Script  (copy this entire file into Apps Script)
// ============================================================
//
//  SETUP STEPS
//  -----------
//  1. Go to https://script.google.com and click "New project"
//  2. Delete any existing code and paste this entire file
//  3. Replace FOLDER_ID below with your Google Drive folder ID
//     (open the folder in Drive — the ID is the long string in
//      the URL after /folders/)
//  4. Click Deploy > New deployment
//       Type: Web app
//       Execute as: Me
//       Who has access: Anyone
//  5. Click Deploy, copy the Web app URL
//  6. Paste that URL into index.html where it says
//     REPLACE_WITH_YOUR_APPS_SCRIPT_URL
//
//  SHARING THE UPLOAD FOLDER WITH CONTRIBUTORS
//  --------------------------------------------
//  - Open the Drive folder
//  - Click Share
//  - Enter each contributor's Gmail address
//  - Set their permission to "Editor" (lets them add/delete photos)
//  - Click Send  — they'll get an email with the folder link
//
//  Photos added to the folder will appear on the website
//  automatically within a few seconds of a page refresh.
// ============================================================

var FOLDER_ID = '1qOFC20iSxWuXPKcRNEucHsSsjc77hXve';

function doGet(e) {
  // Load the set of file IDs that have already been shared publicly.
  // PropertiesService stores up to 500 KB — plenty for file ID lists.
  var props = PropertiesService.getScriptProperties();
  var sharedIds = JSON.parse(props.getProperty('sharedIds') || '[]');
  var sharedSet = {};
  sharedIds.forEach(function(id) { sharedSet[id] = true; });

  var folder = DriveApp.getFolderById(FOLDER_ID);
  var files   = folder.getFiles();
  var images  = [];
  var hasNew  = false;

  while (files.hasNext()) {
    var file = files.next();
    if (file.getMimeType().indexOf('image/') === 0) {
      var id = file.getId();
      if (!sharedSet[id]) {
        // New photo — share it and remember it so we don't repeat this.
        file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
        sharedSet[id] = true;
        hasNew = true;
      }
      images.push(id);
    }
  }

  // Persist the updated set only when something actually changed.
  if (hasNew) {
    props.setProperty('sharedIds', JSON.stringify(Object.keys(sharedSet)));
  }

  // Shuffle so the order is random on every page load.
  for (var i = images.length - 1; i > 0; i--) {
    var j   = Math.floor(Math.random() * (i + 1));
    var tmp = images[i];
    images[i] = images[j];
    images[j] = tmp;
  }

  return ContentService
    .createTextOutput('_photosReady(' + JSON.stringify(images) + ')')
    .setMimeType(ContentService.MimeType.JAVASCRIPT);
}
