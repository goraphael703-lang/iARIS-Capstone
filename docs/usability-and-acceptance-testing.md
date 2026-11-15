# Usability and Acceptance Testing — SUS and UAT

Panel revision #7 set two measures that can only come from real users, not automated tests:
a **System Usability Scale (SUS)** score of **at least 85/100**, and a **User Acceptance
Testing (UAT)** mean of **at least 4.21/5.00**. This document is the instrument and the
scoring method for both — nobody can fabricate these numbers; they need to come from people
who actually use iARIS. Report them as two separate figures (the panel was explicit about
this): SUS measures usability, UAT measures whether the system meets requirements/is
accepted. Don't average them together.

## Who to test

Recruit respondents who represent the real user base, not just classmates unfamiliar with
the system. At minimum, try to cover:
- An IATO Staff member (data entry, import, Review Queue submissions)
- A Super Admin (approvals, Access Control, Audit Logs)
- At least one office role that mainly *views* data (a Dean, Registrar, or Principal/Assistant
  Principal)
- Someone from the Executive Office or LAMP, if available

SUS was validated on small samples — 12–15 respondents is considered reliable, though more
is always better for a thesis defense. Let each respondent actually use the system first
(a short guided walkthrough of their own role's pages: Dashboard, Applicants/Records,
Import, Analytics, Reports, Review Queue as applicable) before handing them the
questionnaire — a SUS/UAT score from someone who never touched the system isn't meaningful.

## Part 1 — System Usability Scale (SUS)

The standard 10-item SUS questionnaire (Brooke, 1986), public domain, unmodified. Each item
is rated **1 (Strongly Disagree) to 5 (Strongly Agree)**.

1. I think that I would like to use this system frequently.
2. I found the system unnecessarily complex.
3. I thought the system was easy to use.
4. I think that I would need the support of a technical person to be able to use this system.
5. I found the various functions in this system were well integrated.
6. I thought there was too much inconsistency in this system.
7. I would imagine that most people would learn to use this system very quickly.
8. I found the system very cumbersome to use.
9. I felt very confident using the system.
10. I needed to learn a lot of things before I could get going with this system.

### Scoring (per respondent)

1. For **odd-numbered items** (1, 3, 5, 7, 9): subtract 1 from the score given.
2. For **even-numbered items** (2, 4, 6, 8, 10): subtract the score given from 5.
3. Add up all 10 adjusted scores (range: 0–40).
4. Multiply the sum by **2.5** → gives a score out of 100.

### Reporting

- Compute the SUS score for every respondent, then report the **mean across all
  respondents** against the ≥85 target.
- A commonly cited scale for interpreting SUS (Bangor, Kortum & Miller, 2009): below 50 is
  "Not acceptable," 50–70 is "Marginal," 70–85 is "Good," **85 and above is "Excellent"** —
  cite this if your panel wants the number put in context, not just the raw figure.

## Part 2 — User Acceptance Testing (UAT)

A 5-point Likert instrument (1 = Strongly Disagree, 5 = Strongly Agree), one set of
statements per objective area so each can be tied back to a specific thesis objective, per
the panel's instruction to connect every objective to a measurable target and a test.
Adjust the exact wording to match your actual stated objectives, but keep the structure:
group by objective, 5-point scale, numeric mean.

### A. Records Management

1. The system accurately stores and organizes applicant records by division (College,
   Integrated School, Graduate Programs, College of Law, iPACE, ETEEAP).
2. Importing admissions data from the portal export works correctly and reliably.
3. I can find a specific applicant's record quickly when I need to.

### B. Role-Based Access and Security

4. I only see the data and functions relevant to my role.
5. I am confident that applicant personal information (name, contact details, address) is
   protected from people who don't need it.
6. The approval process for edits and imports (Review Queue) gives me confidence that
   changes are properly checked.

### C. Dashboards, Analytics, and Reports

7. The dashboard and analytics pages give me a clear, accurate picture of admissions status.
8. I can generate the reports I need (PDF/Excel/CSV) without difficulty.
9. The year-over-year and program-level comparisons are useful for decision-making.

### D. Accountability

10. The audit log gives a clear, trustworthy record of who did what and when.

### Scoring and reporting

- Compute the mean of all item scores, across all respondents, for the overall UAT figure
  against the ≥4.21 target.
- Also report the mean **per objective group (A–D)** — this is what lets you connect each
  objective to its own result, rather than one undifferentiated number.
- A common descriptive scale for a 5-point mean in Philippine thesis conventions:
  1.00–1.80 Strongly Disagree/Poor, 1.81–2.60 Disagree/Fair, 2.61–3.40 Neutral/Satisfactory,
  3.41–4.20 Agree/Very Satisfactory, **4.21–5.00 Strongly Agree/Excellent**.

## What this does not cover

**AI chart explanation / chatbot accuracy** (the panel's other #7 item — "calculate figures
from the data, explain those verified figures; test for wrong trends, missing data, and
misleading interpretations," with a 2-second response target) is **not** included here. The
AI widget was explicitly excluded from this round of work at the user's own instruction, and
it's reported as currently unavailable — testing it meaningfully needs it working first. Once
it's back in scope, that's a separate, code-level test (feed it known data, check the
figures it states against ground truth), not something SUS/UAT covers.

## Suggested defense framing

> "We measured usability with the System Usability Scale, a validated 10-item instrument, and
> acceptance with a custom questionnaire tied to our stated objectives. [N] respondents
> representing [roles tested] completed both after a guided walkthrough of their own role's
> features. The system scored [X]/100 on SUS ([above/below] our 85 target, rated
> '[rating]' on the standard scale) and [Y]/5.00 on UAT ([above/below] our 4.21 target)."
