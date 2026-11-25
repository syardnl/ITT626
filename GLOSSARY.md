# Apartment Maintenance

Residents of one apartment development raise maintenance problems with the management office, which prioritises them, assigns technicians, verifies repairs and monitors service performance.

## People

**Resident**:
A person living in the Property, linked to exactly one Unit, who submits and follows Complaints.
_Avoid_: Tenant (as a synonym), user, occupant

**Resident Type**:
A descriptive classification of a Resident: Owner, Tenant or Student. It has no effect on what a Resident can do.

**Manager**:
Management-office staff who review, prioritise, assign and verify Complaints.
_Avoid_: Admin, building manager (as a role name)

**Technician**:
A person with an account who carries out repairs on Complaints assigned to them; either In-house or a Contractor.
_Avoid_: Worker, repairman

**Contractor**:
A Technician employed by an outside company rather than the management office; works through the same account and screens as an In-house Technician.
_Avoid_: Vendor, third party

**Administrator**:
Staff who configure the system (accounts, Locations, categories, SLA rules) and read the audit log. Not a Manager by default.
_Avoid_: Superuser, admin (in prose)

## Places

**Property**:
The single apartment development the system manages; the root of the Location tree.
_Avoid_: Organization, estate, building

**Location**:
Any node in the Property's tree: a Block, Floor, Unit or Common Area.

**Block**:
A building within the Property, containing Floors.
_Avoid_: Tower, wing

**Floor**:
A level within a Block, containing Units and possibly Common Areas.
_Avoid_: Level, storey

**Unit**:
A single dwelling on a Floor, home to one or more Residents.
_Avoid_: Apartment, house, room

**Location Importance**:
How critical a Location is to residents' daily life: normal, important or essential. Units are always normal; Administrators set it on Common Areas.
_Avoid_: Criticality, weight

**Common Area**:
A space or facility under management responsibility, attached to the Property, a Block or a Floor (e.g. pool, lift, corridor). Complaints are raised only against Units and Common Areas.
_Avoid_: Facility, public area

**Shared Area**:
A Common Area attached directly to the Property rather than to any Block; scored as its own group.
_Avoid_: Estate-wide area

## Work

**Complaint**:
A maintenance problem submitted by a Resident against a Unit or Common Area, tracked from Reported to Closed.
_Avoid_: Issue, ticket, report, case

**Category**:
One of ten fixed kinds of maintenance problem (e.g. Lift, Plumbing & Water Supply), each with a default priority shown to the Manager as a baseline.
_Avoid_: Type, department

**Skill**:
A Technician's ability to handle one Category; there is exactly one Skill per Category.
_Avoid_: Trade, specialty

**Urgency**:
How urgent the Resident says the problem is (Low, Medium, High, Critical); one input to the Priority Score.
_Avoid_: Priority (they are different things)

**Priority Score**:
A 0–100 rule-based score computed from Urgency, safety risk, Affected Units, Location importance and Essential Service outage, which maps to a suggested Priority.

**Priority**:
The level (Low, Medium, High, Critical) that drives a Complaint's SLA targets; the Manager confirms or overrides it.
_Avoid_: Severity, urgency

**Affected Units**:
How many Units a problem affects: one, several (2–10) or many (more than 10, or a whole Block).

**Essential Service**:
Water, electricity, lift or security access; an outage of one lifts a Complaint to at least High Priority.

**SLA Target**:
The maximum time allowed for Response and for Resolution at a given Priority, measured in calendar time.
_Avoid_: Deadline (for the rule itself), KPI

**Response**:
The moment a Manager first reviews a Complaint; ends the response clock.
_Avoid_: Acknowledgement

**Resolution**:
The moment a Manager approves the repair (Resolved); ends the resolution clock, regardless of later Resident confirmation.
_Avoid_: Closure

**Automatic Closure**:
The system closing a Resolved Complaint after 7 days with no Resident response.

**SLA Breach**:
A recorded failure to meet a Response or Resolution target within one Repair Cycle; once recorded it is never cleared.
_Avoid_: Violation, overdue

**Repair Cycle**:
One attempt to resolve a Complaint, with its own resolution target. A Complaint starts with one; a Resident Return starts another.
_Avoid_: Attempt, round, reopen

**Manager Rejection**:
The Manager sending a repair back to the Technician during verification (Pending Verification → Returned); the Repair Cycle and its deadline continue.
_Avoid_: Reject (unqualified)

**Resident Return**:
The Resident reporting that a Resolved problem persists (Resolved → Returned); this starts a new Repair Cycle.
_Avoid_: Reopen, dispute

**Assignment**:
The link between a Complaint and the one Technician currently responsible for it. A Complaint has at most one active Assignment; Reassignment ends it and creates another, moving the Complaint back to Assigned.

**Active Task**:
A Complaint assigned to a Technician that is Assigned, In Progress or Returned; counts against the Technician's maximum tasks.
_Avoid_: Open job, workload item

**Availability**:
A Technician's current state for new work: Available, Busy (can be assigned, ranked lower, with a warning) or On leave (cannot be assigned).

**Follower**:
A Resident who tracks someone else's open Common Area Complaint, in their own Block or a Shared Area, instead of submitting a duplicate. A Follower is not a reporter.
_Avoid_: Subscriber, co-reporter

**Recurring Problem**:
Three or more separate Complaints at the same exact Location and Category within a rolling 90 days, flagged for the Manager to acknowledge or dismiss.
_Avoid_: Repeat issue, hotspot

**Health Score**:
A 0–100 score for each Block, and for the Shared Areas as one group, reflecting 90 days of Complaint volume, Critical Complaints, Recurring Problems, resolution time and SLA Breaches.
_Avoid_: Rating, KPI

**Evidence**:
Photos attached to a Complaint: up to five by the Resident at submission, and before/after photos by the Technician in each Repair Cycle. Never deleted.
_Avoid_: Attachment, upload, proof

**Public Comment**:
A comment on a Complaint visible to its reporter, the Manager and the assigned Technician.

**Internal Comment**:
A comment on a Complaint visible only to the Manager and the assigned Technician, never to Residents.
_Avoid_: Private note, staff note

**Management Report**:
A filtered summary of Complaint data produced for Managers and the JMB committee, viewable or exported as PDF/Excel.
_Avoid_: Report (when it could mean a Complaint)
