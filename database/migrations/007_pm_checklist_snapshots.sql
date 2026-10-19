-- Freeze each generated work order's inspection definition. Editing a PM plan
-- must never rewrite the meaning of historical inspection results.
CREATE TABLE pm_work_order_checklist (
  pm_work_order_id INT UNSIGNED NOT NULL,
  checklist_item_id INT UNSIGNED NOT NULL,
  label VARCHAR(255) NOT NULL,
  value_type VARCHAR(10) NOT NULL,
  unit VARCHAR(20) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (pm_work_order_id, checklist_item_id),
  CONSTRAINT fk_pwoc_wo FOREIGN KEY (pm_work_order_id) REFERENCES pm_work_orders(id),
  CONSTRAINT fk_pwoc_item FOREIGN KEY (checklist_item_id) REFERENCES pm_checklist_items(id),
  CONSTRAINT chk_pwoc_type CHECK (value_type IN ('CHECK','NUMBER','TEXT'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing work orders can only be backfilled from their current definitions.
-- Future orders receive a snapshot inside their generation transaction.
INSERT INTO pm_work_order_checklist (pm_work_order_id,checklist_item_id,label,value_type,unit,sort_order)
SELECT w.id,i.id,i.label,i.value_type,i.unit,i.sort_order
FROM pm_work_orders w JOIN pm_checklist_items i ON i.pm_plan_id=w.pm_plan_id
WHERE i.is_active=1 OR EXISTS (SELECT 1 FROM pm_checklist_results r WHERE r.pm_work_order_id=w.id AND r.checklist_item_id=i.id);
