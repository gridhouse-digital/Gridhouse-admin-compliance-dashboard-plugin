const tab = (id) => async (p) => { await p.click('[data-ghca-tab-target="' + id + '"]'); await p.waitForTimeout(150); };
const drawer = (tabId) => async (p) => {
  await p.click('[data-ghca-tab-target="ghca-tab-employees"]'); await p.click('#open-drawer'); await p.waitForSelector('.gh-drawer--emp .gh-drh-tabs', { state: 'visible' });
  if (tabId) { await p.click('#ghca-acd-drawer-tab-' + tabId); await p.waitForTimeout(200); }
};
const edit = async (p) => { await drawer('administration')(p); await p.locator('[data-ghca-edit-records]').first().click(); await p.waitForSelector('[data-ghca-edit-form]', { state: 'visible' }); await p.waitForTimeout(200); };
const remind = async (p) => { await drawer('')(p); await p.locator('.gh-drawer-ft [data-ghca-reminder]').click(); await p.waitForSelector('[data-ghca-reminder-form]', { state: 'visible' }); await p.waitForTimeout(300); };
module.exports = [
  { name: 'overview-1440', width: 1440, board: 'Admin-Overview', plugin: 'overview.html' },
  { name: 'overview-390', width: 390, board: 'M-Admin-Overview', plugin: 'overview.html' },
  { name: 'employees-1440', width: 1440, board: 'Admin-Employees', plugin: 'tables.html', prep: tab('ghca-tab-employees'), scope: '#ghca-tab-employees', boardScope: 'main' },
  { name: 'employees-834', width: 834, board: 'T-Admin-Employees', plugin: 'tables.html', prep: tab('ghca-tab-employees'), scope: '#ghca-tab-employees', boardScope: 'main' },
  { name: 'employees-390', width: 390, board: 'M-Admin-Employees', plugin: 'tables.html', prep: tab('ghca-tab-employees'), scope: '#ghca-tab-employees' },
  { name: 'inactive-1440', width: 1440, board: 'Admin-Inactive', plugin: 'tables.html', prep: tab('ghca-tab-inactive-employees'), scope: '#ghca-tab-inactive-employees', boardScope: 'main' },
  { name: 'overdue-1440', width: 1440, board: 'Admin-Overdue', plugin: 'lists.html', prep: tab('ghca-tab-overdue'), scope: '#ghca-tab-overdue', boardScope: 'main' },
  { name: 'courses-1440', width: 1440, board: 'Admin-Courses', plugin: 'lists.html', prep: tab('ghca-tab-courses'), scope: '#ghca-tab-courses', boardScope: 'main' },
  { name: 'certificates-1440', width: 1440, board: 'Admin-Certificates', plugin: 'lists.html', prep: tab('ghca-tab-certificates'), scope: '#ghca-tab-certificates', boardScope: 'main' },
  { name: 'drawer-training-1440', width: 1440, board: 'Drawer-Training', plugin: 'drawer.html', prep: drawer(''), scope: '.gh-drawer--emp', boardScope: '.gh-drawer' },
  { name: 'drawer-admin-1440', width: 1440, board: 'Drawer-Administration', plugin: 'drawer.html', prep: drawer('administration'), scope: '.gh-drawer--emp', boardScope: '.gh-drawer' },
  { name: 'drawer-documents-1440', width: 1440, board: 'Drawer-Documents', plugin: 'drawer.html', prep: drawer('documents'), scope: '.gh-drawer--emp', boardScope: '.gh-drawer' },
  { name: 'drawer-comms-1440', width: 1440, board: 'Drawer-Communications', plugin: 'drawer.html', prep: drawer('communications'), scope: '.gh-drawer--emp', boardScope: '.gh-drawer' },
  { name: 'edit-records-1440', width: 1440, board: 'Drawer-EditRecords', plugin: 'drawer.html', prep: edit, scope: '.gh-drawer--emp', boardScope: '.gh-drawer' },
  { name: 'reminder-1440', width: 1440, board: 'Modal-SendReminder', plugin: 'drawer.html', prep: remind, scope: '[data-ghca-reminder-dialog]', boardScope: '.gh-modal' },
  { name: 'sheet-390', width: 390, board: 'M-Admin-Sheet', plugin: 'drawer.html', prep: drawer(''), scope: '.gh-drawer--emp', boardScope: '.gh-sheet, .gh-phone, body' },
  { name: 'external-1440', width: 1440, board: 'Admin-ExternalReview', plugin: 'admin.html', prep: tab('ghca-tab-external-training'), scope: '#ghca-tab-external-training', boardScope: 'main' },
  { name: 'audit-1440', width: 1440, board: 'Admin-AuditData', plugin: 'admin.html', prep: tab('ghca-tab-audit'), scope: '#ghca-tab-audit', boardScope: 'main' },
  { name: 'reports-1440', width: 1440, board: 'Admin-Reports', plugin: 'admin.html', prep: tab('ghca-tab-reports'), scope: '#ghca-tab-reports', boardScope: 'main' },
  { name: 'odp-list-1440', width: 1440, board: 'ODP-List-Admin', plugin: 'library.html', scope: '.gh-odp-main', boardScope: 'main' },
  { name: 'odp-page-1440', width: 1440, board: 'ODP-Page-Admin', plugin: 'bulletin.html', scope: '.gh-odp-main', boardScope: 'main' },
];
