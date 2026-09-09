<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Support\Roles;

/**
 * PRD Section 5.1 + Section 8 — company-level settings.
 *
 * "Company settings: Owner Yes, HM Limited, Recruiter No" — the PRD's
 * matrix says HM has SOME access but never specifies which settings.
 * ASSUMPTION: HM can VIEW company settings (read-only) but cannot WRITE
 * to them — every actual settings change (name, website, custom fields,
 * careers page, billing, deletion) is Owner-only per the explicit bullet
 * list in Section 5.1. Flag for client confirmation if HM needs a
 * specific writable setting.
 */
class CompanyPolicy
{
    public function view(User $user, Company $company): bool
    {
        return $user->company_id === $company->id; // any role can view their own company's settings page
    }

    public function update(User $user, Company $company): bool
    {
        return $user->company_id === $company->id && $user->role === Roles::OWNER;
    }

    public function manageBilling(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function manageCustomFields(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function manageCareersPageSettings(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function manageIntegrations(User $user, Company $company): bool
    {
        // Section 54/113 — integrations are personal, not company-level;
        // every role manages their OWN connected inbox/calendar.
        return $user->company_id === $company->id;
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function transferOwnership(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function viewFullActivityLog(User $user, Company $company): bool
    {
        // Owner sees everything; HM/Recruiter get a SCOPED view instead
        // of being blocked entirely — that scoping is a query concern
        // (like Job/Candidate/Task::scopeVisibleTo()), not a yes/no gate.
        return $user->company_id === $company->id && $user->role === Roles::OWNER;
    }
}
