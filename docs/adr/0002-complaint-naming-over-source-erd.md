# "Complaint" everywhere, not the source ERD's "Issue"

The case study's ERD names the central entity `ISSUE` (`ISSUE_ASSIGNMENT`, `ISSUE_CATEGORY`, ...), while its UI and process text say "complaint". We use **Complaint** in code, API, UI and table names (`complaints`, `complaint_assignments`, `complaint_categories`) so one concept has one name; the design document keeps a mapping from source ERD names to ours.
