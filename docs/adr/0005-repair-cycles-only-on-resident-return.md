# Only a Resident Return starts a new Repair Cycle

A Complaint can reach Returned two ways. A Resident Return (Resolved → Returned) starts a new Repair Cycle with a fresh resolution deadline at the current Priority, because the original target was met and the clock genuinely ended. A Manager Rejection (Pending Verification → Returned) stays in the same Repair Cycle and keeps the original deadline, because the work was never accepted. Restarting the clock on rejection would let poor repairs reset the SLA. Earlier cycles, maintenance records and SLA Breaches are always kept.
