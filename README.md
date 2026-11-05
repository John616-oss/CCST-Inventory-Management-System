# CCST School Inventory System

## Stack
PHP 8.1+, MySQL 8 / MariaDB, MySQLi, HTML/CSS/JavaScript, Bootstrap 5.3. Designed for XAMPP.

## Installation
1. Start Apache and MySQL in XAMPP.
2. Import `database.sql` in phpMyAdmin.
3. Check database settings and `BASE_URL` in `config/config.php`.
4. Open `http://localhost/ccst_system/install.php` and create the first Admin account.
5. Delete `install.php` after setup, then sign in at `login.php`.

For an existing database, run `migrations/convert_inventory_roles.sql` once in phpMyAdmin. It keeps existing records, maps Registrar to Admin, preserves Maintenance, maps other old roles to Staff, and creates the inventory table. Users should sign out and back in after migration.

If the earlier version of that migration was already applied and Maintenance was converted to Staff, run `migrations/restore_maintenance_role.sql` to add the role back to the database. Existing users converted to Staff cannot be identified automatically; update the intended accounts to `maintenance` in phpMyAdmin after checking which users they are.

## Inventory workflow
- The dashboard summarizes equipment records, tracked quantity, items needing attention, and locations.
- Staff accounts open a general inventory dashboard; Maintenance accounts open a separate scan-only workbench.
- Registration offers Staff and Maintenance roles. The first Admin account is created through `install.php`.
- Maintenance accounts cannot open the full equipment register. They scan an equipment QR or a printed room QR and see only active repair items for that scan; they can mark a repair Under Repair or complete it.
- Staff/Admin can print room QR labels from the room column in `inventory.php`.
- Repair start/completion is attributed to the Maintenance account. Staff/Admin can see the latest repairer and full per-item history from the inventory list.
- Maintenance users can update their display name and password from My Profile.
- Staff can submit equipment borrowing requests from `requests.php`; Admin reviews them in `approval_requests.php` and can approve, reject, or record a return. Approved quantities are reserved until returned.
- Admin and Staff can review filtered inventory and borrowing reports from `reports.php`, print them, or export the filtered tables as CSV.
- Add equipment from `inventory.php`. Asset tags are generated as `CCST-INV-YYYY-NNNN` and remain fixed after creation.
- Record equipment such as PCs, TVs, printers, projectors, furniture, and network devices. Include quantity, room, condition, status, custodian, acquisition date, and cost as available.
- Print an item QR label from the QR button in the equipment list. The QR contains its asset tag.
- Scan labels from `scan.php` using a camera on localhost/HTTPS, a USB scanner that types into the tag field, or manual entry.

QR generation and camera scanning use CDN-hosted JavaScript libraries and require an internet connection. Email verification and password reset use the SMTP settings described in `config/mail.php`; on localhost, registration displays a local verification link if SMTP is unavailable.

The system keeps existing school records in the database during the conversion, but they are no longer part of the inventory navigation. Back up the database before applying migrations. Configure HTTPS and protect database credentials before any public deployment.

For an existing inventory database, apply `migrations/add_maintenance_history.sql` to create the repair attribution table. The app also creates it automatically when an inventory or scan page is first opened.

For existing databases, apply `migrations/add_equipment_requests.sql` to create the borrowing approval table. The app can also bootstrap it automatically when an Admin or Staff page is opened.