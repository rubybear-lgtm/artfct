-- Console list (RUB-344 follow-up): store the artifact's title and description
-- at create time so the list can show them and the search box can filter on
-- them. Nullable: artifacts created before this migration have neither.
ALTER TABLE artifacts ADD COLUMN title TEXT;
ALTER TABLE artifacts ADD COLUMN description TEXT;
