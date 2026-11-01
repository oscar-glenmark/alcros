<?php
/**
 * LCRO record identification fields (birth / marriage / death) + Check Record.
 * Expects $identDocType, $draft, $recordVerified in scope.
 */
?>
<div id="identificationBirthBlock" class="identification-type-block space-y-5 <?= $identDocType === 'birth' || $identDocType === '' ? '' : 'hidden' ?>">
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="user" class="w-3.5 h-3.5 text-blue-500"></i> Birth Name on Record *
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="birthNameGrid">
            <input type="text" id="firstNameInput" name="first_name" data-ident-required="birth" placeholder="First name"
                   value="<?= htmlspecialchars($draft['first_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="middleNameInput" name="middle_name" placeholder="Middle name(Optional)"
                   value="<?= htmlspecialchars($draft['middle_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="lastNameInput" name="last_name" data-ident-required="birth" placeholder="Last name"
                   value="<?= htmlspecialchars($draft['last_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
        </div>
    </div>
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-500"></i> Date of Birth *
        </label>
        <input type="date" id="dateOfBirthInput" name="date_of_birth" data-ident-required="birth" value="<?= htmlspecialchars($draft['date_of_birth'] ?? '') ?>"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
    </div>
</div>

<div id="identificationMarriageBlock" class="identification-type-block space-y-5 <?= $identDocType === 'marriage' ? '' : 'hidden' ?>">
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-2">
            <i data-lucide="users" class="w-3.5 h-3.5 text-blue-500"></i> Couples Name on Record *
        </label>
        <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide mb-2">Husband Name</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4" id="husbandNameGrid">
            <input type="text" id="husbandFirstNameInput" name="husband_first_name" data-ident-required="marriage" placeholder="First name"
                   value="<?= htmlspecialchars($draft['husband_first_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="husbandMiddleNameInput" name="husband_middle_name" placeholder="Middle name (optional)"
                   value="<?= htmlspecialchars($draft['husband_middle_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="husbandLastNameInput" name="husband_last_name" data-ident-required="marriage" placeholder="Last name"
                   value="<?= htmlspecialchars($draft['husband_last_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
        </div>
        <p class="text-[10px] font-bold text-gray-500 uppercase tracking-wide mb-2">Wife Name</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="wifeNameGrid">
            <input type="text" id="wifeFirstNameInput" name="wife_first_name" data-ident-required="marriage" placeholder="First name"
                   value="<?= htmlspecialchars($draft['wife_first_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="wifeMiddleNameInput" name="wife_middle_name" placeholder="Middle name (optional)"
                   value="<?= htmlspecialchars($draft['wife_middle_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="wifeLastNameInput" name="wife_last_name" data-ident-required="marriage" placeholder="Last name"
                   value="<?= htmlspecialchars($draft['wife_last_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
        </div>
    </div>
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="heart" class="w-3.5 h-3.5 text-blue-500"></i> Date of Marriage *
        </label>
        <input type="date" id="dateOfMarriageInput" name="date_of_marriage" data-ident-required="marriage" value="<?= htmlspecialchars($draft['date_of_marriage'] ?? '') ?>"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
    </div>
</div>

<div id="identificationDeathBlock" class="identification-type-block space-y-5 <?= $identDocType === 'death' ? '' : 'hidden' ?>">
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="user" class="w-3.5 h-3.5 text-blue-500"></i> Deceased Name on Record *
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="deceasedNameGrid">
            <input type="text" id="deceasedFirstNameInput" name="first_name" data-ident-required="death" placeholder="First name"
                   value="<?= htmlspecialchars($identDocType === 'death' ? ($draft['first_name'] ?? '') : '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="deceasedMiddleNameInput" name="middle_name" placeholder="Middle name (optional)"
                   value="<?= htmlspecialchars($identDocType === 'death' ? ($draft['middle_name'] ?? '') : '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="deceasedLastNameInput" name="last_name" data-ident-required="death" placeholder="Last name"
                   value="<?= htmlspecialchars($identDocType === 'death' ? ($draft['last_name'] ?? '') : '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
        </div>
    </div>
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-500"></i> Date of Death *
        </label>
        <input type="date" id="dateOfDeathInput" name="date_of_death" data-ident-required="death" value="<?= htmlspecialchars($draft['date_of_death'] ?? '') ?>"
               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
    </div>
</div>

<div>
    <div class="flex justify-end">
        <button type="button" id="checkRecordBtn" class="citizen-btn-navy px-4 py-2.5 rounded-xl text-xs whitespace-nowrap shrink-0">
            Check Record
        </button>
    </div>
    <p id="recordCheckHint" class="text-[10px] text-gray-500 mt-2">We verify that your details match LCRO civil records before you can continue.</p>
    <p id="recordStatus" class="text-xs mt-2 <?= $recordVerified ? 'font-semibold text-green-600' : 'hidden' ?>"><?= $recordVerified ? 'Record found — you are registered with the Local Civil Registry Office.' : '' ?></p>
</div>
