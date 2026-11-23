<?php
/**
 * Requester name + relationship to person on certificate (certificate request wizard).
 *
 * Expects: $draft (array), $relationshipDocumentType (string birth|death|marriage), optional $relationshipSelectId
 */

require_once __DIR__ . '/document_request_relationship_requirements.php';

$relationshipDocumentType = trim((string) ($relationshipDocumentType ?? ($draft['document_type'] ?? '')));
$relationshipSelectId = (string) ($relationshipSelectId ?? 'requesterRelationshipSelect');
$catalog = documentRequestRelationshipCatalog();
$relationshipOptions = $catalog[$relationshipDocumentType] ?? [];
$selectedRelationship = trim((string) ($draft['requester_relationship'] ?? ''));
if ($selectedRelationship !== '' && !isset($relationshipOptions[$selectedRelationship])) {
    $selectedRelationship = '';
}
$showRequirementsPanel = $selectedRelationship !== ''
    && isAllowedDocumentRequestRelationship($relationshipDocumentType, $selectedRelationship);

$embedDocumentTypeSelect = !empty($embedDocumentTypeSelect);
$documentTypeSelectId = (string) ($documentTypeSelectId ?? 'requestStartDocumentType');
$documentTypeFieldLabel = (string) ($documentTypeFieldLabel ?? 'Select Certificate Type *');
$documentTypePlaceholder = (string) ($documentTypePlaceholder ?? 'Select Certificate Type');
$currentDocumentType = (string) ($draft['document_type'] ?? '');
$validTypesForSelect = isset($validTypes) && is_array($validTypes) ? $validTypes : [];
?>
<div class="space-y-3" id="requesterRelationshipBlock" data-relationship-doc-type="<?= htmlspecialchars($relationshipDocumentType, ENT_QUOTES) ?>">
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
            <i data-lucide="user-check" class="w-3.5 h-3.5 text-blue-500"></i> Your Name (Requester) *
        </label>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="requesterNameGrid">
            <input type="text" id="requesterFirstNameInput" name="requester_first_name" required placeholder="First name"
                   value="<?= htmlspecialchars($draft['requester_first_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="requesterMiddleNameInput" name="requester_middle_name" placeholder="Middle name(Optional)"
                   value="<?= htmlspecialchars($draft['requester_middle_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <input type="text" id="requesterLastNameInput" name="requester_last_name" required placeholder="Last name"
                   value="<?= htmlspecialchars($draft['requester_last_name'] ?? '') ?>"
                   class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
        </div>
        <p class="text-[10px] text-gray-500 mt-1">Full name of the person submitting this request. This may differ from the name on the certificate.</p>
    </div>
    <?php if ($embedDocumentTypeSelect && $validTypesForSelect !== []): ?>
    <div>
        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5" for="<?= htmlspecialchars($documentTypeSelectId) ?>">
            <i data-lucide="file-text" class="w-3.5 h-3.5 text-blue-500"></i> <?= htmlspecialchars($documentTypeFieldLabel) ?>
        </label>
        <select name="document_type" id="<?= htmlspecialchars($documentTypeSelectId) ?>" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
            <option value="" disabled hidden <?= $currentDocumentType === '' ? 'selected' : '' ?>><?= htmlspecialchars($documentTypePlaceholder) ?></option>
            <?php foreach ($validTypesForSelect as $t): ?>
            <option value="<?= htmlspecialchars((string) $t) ?>" <?= $currentDocumentType === (string) $t ? 'selected' : '' ?>><?= htmlspecialchars(documentTypeLabel((string) $t)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end">
        <div class="sm:col-span-1">
            <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5" for="<?= htmlspecialchars($relationshipSelectId) ?>">
                <i data-lucide="users" class="w-3.5 h-3.5 text-blue-500"></i> Relationship to Person on Record *
            </label>
            <select name="requester_relationship" id="<?= htmlspecialchars($relationshipSelectId) ?>" required
                    class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                    data-requester-relationship>
                <option value="" disabled <?= $selectedRelationship === '' ? 'selected' : '' ?>>Select relationship</option>
                <?php foreach ($relationshipOptions as $slug => $label): ?>
                <option value="<?= htmlspecialchars($slug) ?>" <?= $selectedRelationship === $slug ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div id="requesterRelationshipRequirementsHost" data-rel-req-host>
        <?php if ($showRequirementsPanel): ?>
        <?= renderDocumentRequestRelationshipRequirementsPanel($relationshipDocumentType, $selectedRelationship) ?>
        <?php endif; ?>
    </div>
</div>