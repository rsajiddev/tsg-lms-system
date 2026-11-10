# TSG Academy LMS

PHP 8 / MySQL / MVC application that runs on XAMPP with no Composer packages, no CDN and no build step. The original marketing site is kept untouched in `legacy-site/` for reference; the LMS itself is served by a single front controller and Router.

This README is the single catalogue of what is implemented. Every feature that ships gets recorded here as it is finished, so one file answers "what does the system do today?".

## Quick start

1. Copy this folder into `htdocs/`.
2. Create the MySQL database `tsg_academy_lms` in phpMyAdmin.
3. Import `database/migrations/001_initial_schema.sql`.
4. Open `.env` and set `APP_URL`, the DB values, and the `MAIL_*` block (the sending mailbox and its app password). That one mailbox is the "from" address of every automated email and the inbox that receives all internal alerts, so nothing else has to be configured. Every comment in `.env` states what its variable is for, so a new owner can configure the installation without reading any PHP.
5. Start Apache and MySQL, then open the project URL.
6. Create the first admin with `php bin/create-admin.php`.

`database/migrations/001_initial_schema.sql` is the canonical schema for fresh installs **and** for incremental upgrades. New schema changes belong in that file; its guarded `ALTER`/`INSERT` statements let an existing database pick up the additions when the updated file is imported again. Back up the database before re-importing. Mail transport must be configured before registration and password-reset OTPs can be delivered.

Dashboard personal tasks and timed email alerts are available to students and admins. After re-importing the schema, schedule `bin/send-dashboard-reminders.php` to run every minute so alerts are sent at their selected time. For this XAMPP install, a macOS/Linux crontab entry is `* * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/TSG-Academy-LMS/bin/send-dashboard-reminders.php`; on another machine, use its PHP binary and project path. The worker uses the existing `MAIL_*` SMTP settings and safely skips overlapping runs.

## Implemented features

### 1\. Admin Login & Dashboard Security
* Admin apne login credentials ke zariye dashboard mein login karega.
* Admin dashboard ko fully secure banaya gaya hai.
* Koi external user ya student admin dashboard mein login nahi kar sakta.
* Sirf authorised admin hi dashboard access kar sakta hai.
* Dashboard ka layout do main sections mein divided hai:
    * Left side par sidebar navigation hogi.
    * Right side par selected page ka content show hoga.
* Sidebar mein jis button ya navigation link ko active kiya jayega, uska relevant page right side par open hoga.

### 2\. Overview Dashboard
* Overview page par complete dashboard summary show hogi.
* Total records aur historical data available hoga.
* Charts aur graphs ke zariye performance statistics show hongi.
* Important records aur management summaries yahan display hongi.

### 3\. Manage Courses
Admin yahan se students ke liye different courses create aur manage kar sakta hai.
Create Course mein following fields hongi:
* Course Title
* Course URL
* Course Category
* Visibility (Published / Draft)
* Course Cover Image
* Course Duration
* Course Level (Beginner / Medium / Professional)
* Recorded Course Price
* Live + Recorded Course Price
* Course Card Summary
* Short Course Description
Course Management:
* Neeche uploaded courses ki complete list show hogi.
* Admin kisi bhi course ko edit kar sakta hai.
* Admin courses delete bhi kar sakta hai.
* Student Course Access ke zariye specific students ko specific courses assign kiye ja sakte hain.

### 4\. Lesson Library
* Admin yahan se har course ke lessons upload aur manage karega.
* Agar kisi course mein 10 lessons hain, toh admin unhein one by one upload kar sakta hai.
* Lessons daily basis par bhi add kiye ja sakte hain.
Create Lesson mein fields:
* Target Course Selection
* Module Title
* Lesson Title
* Visibility (Published / Draft)
* Lesson Content
* Video URL (YouTube ya kisi doosre platform ki link jaise record kar k video)
* Video Duration / Length
* Zoom Meeting Link (Live Classes ke liye)
* Save Lesson Button
Video Completion Rules:
* Admin video completion percentage set kar sakta hai.
* Example: Agar 80% video watch karna compulsory hai, toh student ke 80% video complete karne par lesson automatically completed mark ho jayega.
Lesson Management:
* Neeche tamam courses ke uploaded lessons show honge.
* Admin lesson details dekh sakta hai.
* Lessons edit aur delete kar sakta hai.
* Delete All Lessons ka option bhi available hoga.

### 5\. Quiz Management
Admin yahan se courses aur students ke liye quizzes create aur manage karega.
Quiz Creation Fields:
* Course Selection
* Week Selection
* Assignment Type:
    * Practice Test
    * Module Test
    * Final Test
* Question Bank / Question Category
* Time Limit
* Passing Marks
* Allowed Attempts
* Quiz Title
* Instructions / Rules
* Available From (Start Date)
* Available Until (End Date)
* Visibility (Published / Draft)
Quiz Workflow:
* Admin Create Quiz par click karega.
* Quiz ki tamam details save ho jayengi.
* Uske baad Add Questions ka section open hoga.
* Admin yahan MCQs aur unke multiple-choice answers add kar sakta hai.
Additional Options:
* Final Test Retake Requests
* Delete All Quizzes

### 6\. Coding Challenge
* Yeh module specially Full Stack Development students ke liye hai.
* Admin yahan live coding challenges create kar sakta hai.
Challenge Creation Fields:
* Challenge Title
* Course Selection
* Challenge Instructions
* HTML Starter Code
* CSS Starter Code
* Test Cases
* Save Challenge
Example Challenge:
* HTML mein ek heading create karein.
* Heading ka text colour Red hona chahiye.
Admin challenge save karega aur woh automatically student dashboard par available ho jayega.

### 7\. Quiz Reports
* Admin tamam students ki quiz performance reports dekh sakta hai.
* Kis student ne quiz pass ya fail kiya, uski details available hongi.
* Total attempts show honge.
* Quiz attempt aur submission ki date aur time show honge.
* Student-wise quiz performance track ki ja sakti hai.

### 8\. Assignments
Is section mein do main functionalities hongi.

A. Assignment Reviews
* Students ke submitted assignments yahan show honge.
* Admin submitted assignments review kar sakta hai.
* Assignment approve ya feedback ke liye return kiya ja sakta hai.

B. Create Assignment
Fields:
* Assignment Title
* Category
* Course / Week
* Duration
* Due Date
* Status
Assignment Catalogue:
* Tamam created assignments ki list show hogi.
* Submitted assignments ki details bhi available hongi.

### 9\. Students Management
* Admin tamam registered students ki details dekh sakta hai.
* Student profile aur account information access kar sakta hai.
* Individual student ki activity monitor kar sakta hai.
Student Activity Tracking:
* Student ne kab login kiya.
* Kitni der login raha.
* Kitni baar login kiya.
* Complete login history aur activity records.
* Admin zaroorat par student activity records clear bhi kar sakta hai.

### 10\. Attendance Confirmation Window
* Attendance sirf **Live + Recorded** students ke scheduled live classes par mark hoti hai; login akela attendance nahi banata.
* Admin har live lesson ke saath UTC date aur time set karta hai. Ek UTC din mein academy ki sirf ek live class schedule ho sakti hai.
* Class start par enrolled Live + Recorded students ke liye attendance window khulti hai. Admin ka configured grace time khatam hone par unconfirmed students Absent record hote hain, chahe woh login na hue hon.
* Student dashboard ka circle poore scheduled class plan ki coverage dikhata hai; attendance percentage Present / decided classes se alag calculate hoti hai.
* Admin dashboard, reports aur Live + Recorded certificate eligibility isi per-class attendance record ko use karte hain.

### 11\. Announcements
* Admin students ke liye announcements create kar sakta hai.
* Example: Aaj ki class late start hogi.
Announcement Fields:
* Announcement Title
* Message
* Target Audience
Admin announcement in logon ke liye send kar sakta hai:
* All Students
* Selected Students
* Specific Course Students
### 12\. Chats
* Admin individual students ke saath in-app chat kar sakta hai.
* Students aur admin ke darmiyan direct communication possible hogi.
* Messages aur notifications dashboard ke andar available hongi.

### 13\. Certificates
* Official request student **My courses** se bhejta hai; us se pehle har published lesson complete hona chahiye. Request server par dobara check hoti hai, is liye button ya browser badal kar eligibility bypass nahi hoti.
* Official eligibility rules:
  * Weighted overall course grade kam az kam **60%** ho.
  * **Live + Recorded** access plan ke liye attendance kam az kam **75%** ho; calculation access grant ke baad ke decided attendance days par hoti hai.
  * **Recorded Only** plan par live attendance gate apply nahi hota.
  * Course ke tamam published lessons complete hon. Draft ya removed lessons count nahi hote.
  * Admin certificate queue mein request review karke signed **JPG ya PDF** (max 5 MB) upload karta hai. Issuance par student ko email/in-app notification milti hai aur certificate us ke Academy ID ke saath public verification par available hota hai.
  * Admin issued certificate ko reason ke saath revoke kar sakta hai. Revoked record public register mein rehta hai, download nahi hota, aur student ko notification milti hai. Admin revoked request par replacement upload karke reissue kar sakta hai.
  * Issued certificate student ke **My courses** page se download hota hai. Public verification par Academy ID se us account ke official certificates aur un ke current issued, expired, ya revoked statuses dekhe ja sakte hain; name search official verification ka zariya nahi hai.

### 14\. Revenue & Payments
* Admin tamam students ki payment history dekh sakta hai.
* Revenue aur payment records manage kar sakta hai.
* Admin apne bank account aur bank cards add kar sakta hai.
* Payment summaries aur related notifications available hongi.
* Payment reject karne se pehle OK/Cancel confirmation aati hai; OK ke baad processing state dikhai deti hai.

### 15\. Notifications
* Admin ko important dashboard notifications receive hongi.
* Payment updates, student activities aur other important events ki notifications show hongi.

### 16\. Reports
Reports section mein tamam important modules ki reports available hongi.
* Quiz Reports
* Monthly Reports
* Assignment Reports
* Attendance Reports
* Student Performance Reports
* Overall Reports
Admin tamam important activities aur performance ko monitor kar sakta hai.

### 17\. Moderation
* Public website ke home page par publish hone wale reviews pehle admin ke paas moderation ke liye aayenge.
* Admin har review ko check karega.
* Admin review approve ya reject kar sakta hai.
* Sirf approved reviews public home page par publish honge.

### 18\. Security Settings
Admin yahan se authentication aur login security rules manage kar sakta hai.

A. OTP Authentication Settings
* OTP Expiry Time
* OTP Resend Waiting Time
* Maximum OTP Verification Attempts
* OTP Resend Limits
* Resend Window Configuration
B. Login Protection
* Maximum Login Attempts set karna.
* Example: Maximum 5 login attempts allowed honge.

C. Registration Limits
* Registration requests ki maximum limit set hogi.
* Example: 1 hour ke andar maximum 5 registration attempts.
* Limit complete hone par student ko next hour tak wait karna hoga.

D. Password Reset Limits
* Password reset requests ki limit set hogi.
* Example: 5 minutes ki window mein maximum 2 password reset requests allowed hongi.

### 19\. My Account
* Admin ki complete profile details yahan available hongi.
* Admin apne account credentials update kar sakta hai.
* Account information aur login details manage kar sakta hai.

### 20\. Recent Activity
* Admin ki taraf se ki gayi tamam recent activities yahan show hongi.
* Kis admin action ko perform kiya gaya, uski history available hogi.
* Admin apni recent activity monitor kar sakta hai.



### Student Dashboard – Complete Functionalities

Neeche aapke Student Dashboard ki complete explanation ko pehle Roman Urdu aur phir UK English mein professional, easy-to-understand aur structured points mein convert kiya gaya hai. Isay aap apni project documentation (SRS/SDD) aur development team ke liye bhi use kar sakte hain.

### Part 1: Roman Urdu
### 1\. Student Registration & OTP Verification
* Student admin ke registration form ke zariye apna account register karega.
* Registration form mein required information, Gmail address aur password enter karega.
* Registration ke baad student ko email par OTP receive hoga.
* Student OTP enter karke apna account verify karega.
* OTP verification successful hone ke baad student ka account register ho jayega.
* Account registration ke baad student automatically Payment Page par redirect ho jayega.

### 2\. Course Payment & Approval Process
* Payment page par admin ke dashboard mein configured bank account details show hongi.
* Student apne selected course ki payment diye gaye bank account mein transfer karega.
* Payment complete karne ke baad student:
    * Payment receipt upload karega.
    * Transaction ID enter ya upload karega.
* Payment submission ke baad student ko admin ki approval ka wait karna hoga.
* Payment submission par admin aur student dono ko email notification receive hogi.
* Admin payment details aur uploaded receipt verify karega.
* Payment confirm hone ke baad admin apne dashboard se payment approve karega.
* Approval ke baad student ko confirmation message aur email receive hogi.
* Student apne registered Gmail aur password ke zariye dobara login kar sakega.
* Successful login ke baad Student Dashboard open ho jayega.

### 3\. Student Overview Dashboard
* Overview student dashboard ka main page hoga.
* Is page par student ki overall academic performance aur activities ka summary show hoga.
* Different cards, charts aur statistics ke zariye important information display hogi.

A. Attendance Card
* Student apni scheduled live class ka attendance class start ke baad mark karega.
* Attendance history har class, course aur date ke saath available hogi.
* Present, Absent aur Waiting status show honge; login na karne par bhi guzri hui class Absent record hogi.
* Circular chart poore course ke scheduled live classes ki coverage dikhayega; attendance percentage Present / decided classes se alag hogi.

B. Weekly Attendance Statistics
* Student apni weekly live-class attendance performance dekh sakega.
* Har week mein kitni classes Present aur kitni Absent rahin, ye show hoga.

C. Video Watching Progress
* Admin jo recorded lessons upload karega, student unki video links ke zariye lessons watch karega.
* Dashboard par video watching progress percentage show hogi.
* Example: Agar student ne kisi video ka sirf 10% dekha hai, toh 10% progress show hogi.
* Agar student ne 90% video complete kar li hai, toh 90% progress show hogi.

D. Performance Summary Cards Overview page par following summary cards honge:
* My Courses
* Completed Lessons
* Quizzes
* Attendance
* Learning Progress

### 4\. My Courses
* Overview page par My Courses ka section available hoga.
* Is section mein student ke purchased aur approved courses cards ki form mein show honge.
* Student sidebar ke My Courses button par click karke bhi apne tamam purchased courses dekh sakta hai.
* Course cards mein relevant course information available hogi.

### 5\. Lessons
* Lessons section mein student ko admin ki taraf se uploaded aur published lessons show honge.
* Sirf wohi lessons student ko available honge jinhein admin ne publish ya approve kiya hoga.
* Student apne enrolled courses ke lessons access kar sakega.
* Recorded video lessons aur live Zoom meeting links yahan available honge.
* Student apni lesson completion aur video watching progress track kar sakega.

### 6\. Payments & Payment History
* Payments page par student ki tamam course payments ka record available hoga.
* Student apni payment history check kar sakega.
* Uploaded payment receipts ka record bhi available hoga.
* Student Download Receipt button par click karke apni payment receipt PDF ya available format mein download kar sakega.
* Student apni payment details aur transaction information bhi dekh sakega.

### 7\. Quizzes & Results
* Student yahan se available quizzes attempt kar sakega.
* Practice Tests, Module Tests aur Final Tests access kar sakega.
* Quiz instructions aur time limits dekh sakega.
* Quiz submit karne ke baad results check kar sakega.
* Previous quiz attempts aur performance results bhi available honge.

### 8\. Coding Challenges
* Yeh module specially Full Stack Web Development students ke liye available hoga.
* Students yahan HTML, CSS aur JavaScript ki practical coding practice kar sakenge.
* Admin ki taraf se upload kiye gaye coding challenges yahan show honge.
* Student coding editor mein code likh kar challenges solve karega.
* Test cases ke zariye apne code ko check kar sakega.
* Coding practice aur challenge performance track kar sakega.

### 9\. Assignments
* Assignments page par admin ki taraf se upload kiye gaye tamam assignments show honge.
* Student assignment details aur instructions dekh sakega.
* Assignment ko review karke given deadline ke andar submit kar sakega.
Assignment Submission Options:
  * GitHub Project Link
  * Project ZIP File
  * Required Assignment Files
Submission Process:
  * Student apna completed project upload karega.
  * GitHub repository ka link provide kar sakega.
  * Code ki ZIP file upload kar sakega.
  * Assignment deadline ke mutabiq submission complete karega.
  * Submitted assignment admin ke review ke liye available ho jayegi.
  * Student assignment review status aur feedback check kar sakega.

### 10\. Reports
* Reports page par admin ki taraf se student ke liye selected reports show hongi.
* Admin apne dashboard ke Reports section se specific student ko report send kar sakta hai.
* Jab admin kisi student ko report share karega, toh woh report automatically us student ke Reports page par available ho jayegi.
* Student report online view kar sakega.
* Student Download PDF button ke zariye report PDF format mein download kar sakega.

### 11\. Notifications
* Notifications page par admin ki taraf se bheji gayi tamam important updates show hongi.
* New courses ki notifications receive hongi.
* New lessons aur course updates ki notifications show hongi.
* New assignments aur quizzes ki notifications receive hongi.
* Important announcements aur other updates bhi yahan display honge.

### 12\. Chats / Personal Chat
* Student admin ke saath direct personal chat kar sakega.
* Agar student ko kisi lesson, assignment ya course ke regarding koi issue ho, toh woh admin ko message kar sakega.
* In-app messaging system ke zariye student aur admin communicate kar sakenge.
* Student apne messages aur replies dashboard mein dekh sakega.

### 13\. Account Settings
* Student apni account information manage kar sakega.
* Apna name update kar sakega.
* Apni email information manage kar sakega.
* Password change kar sakega.
* Apni personal profile details update kar sakega.
* Account security aur login credentials manage kar sakega.

### 14\. Public Contact Workflow
* Website par ek dedicated contact page hai (left side par "get in touch" intro aur phone/WhatsApp/email/office details, right side par dark navy enquiry form).
* Koi bhi visitor (account ke bagair) form bhar kar naam, email, phone (optional), subject aur message bhej sakega.
* Form submit hone par do professional emails jate hain, dono same company mailbox (`MAIL_FROM_ADDRESS`) se:
  * **Admin/Company inbox** par enquiry ki full details (naam, email, phone, subject, message) — reply karne ke liye.
  * **Sender** ko acknowledgement — "aapka message receive ho gaya, hamari team jald contact karegi".
* Enquiry ki details `contact_messages` table me bhi save hoti hain aur Admin Dashboard ke **Review moderation → Contact messages** section me sender details, subject aur message ke saath dikhai deti hain.
* Submit karte waqt visitor ko sending loader milta hai; request complete hone par success confirmation dikhai jati hai. Review moderation inbox se admin confirmation ke baad saved contact messages clear kar sakta hai.
* `email_logs` table me delivery trace (template, recipient, subject, status, time) record hota hai, body nahi.
* Submission par CSRF check, field validation aur IP-based throttle (RateLimiter, 5 per hour) lagta hai, taake spam na ho.
* Handover par company sirf `MAIL_USERNAME`, `MAIL_PASSWORD` aur `MAIL_FROM_ADDRESS` badle to enquiry usi ki mailbox par aayengi, koi code edit ki zaroorat nahi.

### 15\. Admin Settings – Clear All Data & Reset System
Testing ke waqt jo data banta hai (students, enrollments, payments, quiz attempts, results, progress, submissions, attendance, certificates, messages) wo Admin Dashboard ke **Settings** page se ek jagah clear ho sakta hai.

* **Clear All Data button** (`CLEAR ALL DATA` phrase + irreversible tick + server-side re-check):
  * Tamam test students apne poore data graph ke saath delete hote hain — enrollments, payments, quiz attempts aur results, lesson progress, submissions, grades, attendance, certificate records aur uploaded files, chats, sessions.
  * ID counter bhi reset ho kar saved scheme ke mutabiq number one se dubara shuru hota hai.
  * **Delete NAHI hota:** admin accounts, login credentials, system settings, ID format configuration, courses aur content, email delivery log. Database ke primary/foreign keys safely handle hote hain (FK order ke mutabiq deletion, transaction me).
* **Reset ID Counter button** (koi record delete nahi karta):
  * Student/certificate ID counter ko fresh starting serial par le jata hai (example: prefix TSG-CER + year 2026 + serial 001 se next IDs TSG-CER-2026-001, -002, -003 generate honge).
  * Field blank chhorne par existing sabse bade serial ke baad se resume ho jata hai.
  * **Duplicate protection:** jo serial kisi existing student ya issued certificate ke paas hai, counter usay kabhi nahi deta — sequence chalte waqt taken numbers skip ho jate hain, is liye reset se existing IDs kabhi touch nahi hote.
* Student directory ka **Delete all students** tool bhi ab fail nahi hota: shared reports (`report_shares`) ka foreign key purge me safely delete ho jata hai (pehle FK 1451 error aata tha).
* Poora flow `tests/purge-tools-smoke.php` me automated hai — wrong phrase se kuch delete nahi hota, wipe sirf zero state banata hai, aur counter reset ke baad issued IDs prove hote hain.

### 16\. Email Notification System – Recipient Routing
Har automated email ka recipient `.env` se decide hota hai. Company ki mail aur developer ki mail **alag inboxes** me jaati hain — developer ki notifications kabhi company ki Gmail par mix nahi hoti.

* **Recipient mapping** (`App\Services\MailService` ke readers):
  * **Student notifications** (OTP, payment confirmation, approval/rejection) → student ka apna registered email.
  * **Company notifications** (HR registration aur payment queue, certificate requests) → `MAIL_FROM_ADDRESS` (company ka sending mailbox).
  * **Developer notifications** (registration, payment submitted, payment approved, monthly revenue report) → `MAIL_DEVELOPER_ADDRESS` (alag developer Gmail). Address blank/invalid ho to developer copies company mailbox par **fall back** karti hain (drop nahi hoti), lekin live install me hamesha alag developer address set karein.
  * **Admin copy** (payment approval record) → jis admin ne approve kiya uska `reviewer_email`.
* **Developer revenue share**: percentage `DEVELOPER_SHARE_PERCENT` (`.env`) se aata hai, default 15. Ek number badalne (maslan 18) se payment emails, monthly report, Revenue & Payments card aur PDF receipt — sab jagah calculation aur label saath update ho jaate hain. Pending payment par "expected share" dikhta hai, approved hone par hi confirmed earning me count hota hai.
* **Notify Developer button** (Revenue & Payments page): current month ka approved revenue currency-wise summary banakar sirf developer inbox par bhejta hai, aur admin ko **asli** success/failure response dikhata hai (mail log me detail). **Duplicate protection:** same month ki same figures (currency + count + total ka fingerprint) dubara click karne par doosri notification nahi jaati — naye approvals figures badal dete hain, tab hi dobara send hota hai.
* `.env` aur `.env.example` synchronize hain (`.env.example` placeholder template hai, real credentials nahi). Routing aur separation `tests/email-notification-smoke.php` aur `tests/interface-render-smoke.php` me assert hoti hai.

## Automated test suite (smoke tests)
The `tests/` directory holds standalone CLI smoke tests. Each one exercises a specific feature or
end-to-end flow, prints a `PASS`/`FAIL` line per assertion, and exits non-zero on any failure so the
result is easy to script. They need no browser and send no real mail.

### How to run
```
/Applications/XAMPP/xamppfiles/bin/php -d error_reporting=0 tests/<name>.php --database=<scratch_db>
```
Point `--database` at a throwaway schema (name must contain `tmp`, `check`, `scratch` or `test`);
import `database/migrations/001_initial_schema.sql` into it first. Never aim a test at the live
database. Every test cleans up the rows and files it creates, and restores any settings it changes.

### Safety mechanisms
* **Scratch-database guard** — a test refuses to run unless the schema name looks temporary, so a
  routine check cannot wipe or pollute real data.
* **Loopback SMTP catcher** — mail tests start a throwaway SMTP server on `127.0.0.1`, point the
  mail env at it, and read the raw messages off the wire. This verifies the full SMTP transport
  (auth, headers, MIME body) without any internet or inbox.
* **Self-contained fixtures** — each test seeds only what it needs and removes it afterwards.

### What each file covers
| Test file | Purpose |
| --- | --- |
| `auth-flow-smoke.php` | Registration, OTP verification and resend throttle, activation (with one-time token revoke and replay rejection), successful/failed login, password reset, duplicate handling, and the OTP/reset rows written to `email_logs`. |
| `enrol-flow-smoke.php` | Course-first checkout: enrolment, access plans and the enrolment intent/checkout flow. |
| `contact-flow-smoke.php` | Public contact form: visitor enquiry sends both emails, saves the enquiry for the admin inbox, carries the right recipients, survives CSRF/field validation, and is logged in `email_logs`. |
| `email-notification-smoke.php` | All official email templates render as real HTML + plain-text alternatives, with correct recipient routing (OTP, payment, certificate, contact, admin alerts) and the in-app notification badge counts. |
| `interface-render-smoke.php` | Renders every admin and student surface against seeded fixtures (no PHP notices, no failed reads), asserts the sidebar unread/due badges, and checks the `.env` handover surface so the mail keys stay consistent with the code that reads them. |
| `attendance-flow-smoke.php` | Daily attendance rows, the confirmation window and grace minutes, present/absent sweeps, and admin/student summary consistency. |
| `lesson-watch-flow-smoke.php` | Opening a lesson and recording watch time / completion. |
| `video-protection-flow-smoke.php` | Video protection rules: lesson media access, concurrent-device limits and anti-piracy behaviour. |
| `live-class-flow-smoke.php` | Live class meeting links, scheduling and access gating. |
| `certificate-verify-smoke.php` | Official request eligibility and public certificate verification: valid/expired/revoked states, Academy ID lookup, official download link, and invalid lookup handling. |
| `admin-identity-flow-smoke.php` | Admin account provisioning, unified ID prefixes and identity handling. |
| `purge-tools-smoke.php` | Admin data-purge tools: FK-safe deletion order and the audit trail (destructive, so it only ever runs on a scratch schema). |
| `TEST-PLAN.md` | The living log of what has been tested, the results, and the items deliberately left untested (e.g. browser-only visual checks). |

Run the suite after any code change to the affected area — a green test is the project's definition
of "this flow still works". New features should ship with a matching smoke test.
