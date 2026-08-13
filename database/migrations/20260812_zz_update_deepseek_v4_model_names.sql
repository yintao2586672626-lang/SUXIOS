UPDATE `ai_model_configs`
SET
    `name` = CASE
        WHEN `model_key` = 'deepseek_chat' THEN 'DeepSeek V4 Flash'
        WHEN `model_key` = 'deepseek_reasoner' THEN 'DeepSeek V4 Pro'
        ELSE `name`
    END,
    `model_name` = CASE
        WHEN `model_key` = 'deepseek_chat' THEN 'deepseek-v4-flash'
        WHEN `model_key` = 'deepseek_reasoner' THEN 'deepseek-v4-pro'
        ELSE `model_name`
    END
WHERE `provider` = 'deepseek'
  AND (
      (`model_key` = 'deepseek_chat' AND `model_name` = 'deepseek-chat')
      OR (`model_key` = 'deepseek_reasoner' AND `model_name` = 'deepseek-reasoner')
  );
