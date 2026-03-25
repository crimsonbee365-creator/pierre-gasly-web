Database files added:
- current_main_structure.sql -> current schema structure with cleaned default seed data
- clean_data_reset.sql -> resets data without removing the rewards table structure

Notes:
- Keeps master admin only
- Resets user_rewards data instead of dropping the table
- Keeps only PETRON, PRYCE GASES, REGASCO active
- Keeps only 11kg and 22kg active
