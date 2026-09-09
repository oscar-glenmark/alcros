LCRO Certification form backgrounds (Forms 1A / 2A / 3A)

Source PDF: certifications.pdf
  Page 1 → death.png   (Form 2A)
  Page 2 → marriage.png (Form 3A)
  Page 3 → birth.png    (Form 1A)

To re-import from PDF, run from repo root:

  powershell -File scripts/import_certification_pdfs.ps1

Then resync DB templates:

  php scripts/resync_certification_templates.php

Open Print → Certification from Civil Records to preview. Use print
calibration if field positions need fine-tuning.
