<?php

return [
    'required' => 'حقل :attribute مطلوب.', 'present' => 'يجب أن يكون حقل :attribute موجوداً.',
    'string' => 'يجب أن يكون :attribute نصاً.', 'integer' => 'يجب أن يكون :attribute عدداً صحيحاً.',
    'numeric' => 'يجب أن يكون :attribute رقماً.', 'email' => 'يجب إدخال عنوان بريد إلكتروني صالح في :attribute.',
    'in' => 'القيمة المحددة لحقل :attribute غير صالحة.', 'exists' => 'السجل المحدد في :attribute غير متاح.',
    'unique' => 'قيمة :attribute مستخدمة بالفعل.', 'distinct' => 'يحتوي :attribute على قيمة مكررة.',
    'array' => 'يجب أن يكون :attribute قائمة.', 'boolean' => 'يجب تحديد قيمة صحيحة لحقل :attribute.',
    'file' => 'يجب أن يكون :attribute ملفاً.', 'uploaded' => 'تعذر رفع :attribute.',
    'mimes' => 'يجب أن يكون :attribute ملفاً من نوع :values.', 'mimetypes' => 'يجب أن يكون :attribute ملفاً من نوع :values.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد الامتدادات التالية: :values.',
    'uuid' => 'يجب أن يكون :attribute معرفاً صالحاً.', 'regex' => 'تنسيق :attribute غير صالح.',
    'date' => 'يجب أن يكون :attribute تاريخاً صالحاً.', 'date_format' => 'يجب أن يكون :attribute بالتنسيق :format.',
    'after_or_equal' => 'يجب أن يكون :attribute في تاريخ :date أو بعده.',
    'before_or_equal' => 'يجب أن يكون :attribute في تاريخ :date أو قبله.',
    'confirmed' => 'تأكيد :attribute غير مطابق.', 'url' => 'يجب أن يكون :attribute رابطاً صالحاً.',
    'min' => ['numeric' => 'يجب ألا يقل :attribute عن :min.', 'string' => 'يجب ألا يقل طول :attribute عن :min أحرف.', 'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.', 'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.'],
    'max' => ['numeric' => 'يجب ألا يزيد :attribute عن :max.', 'string' => 'يجب ألا يزيد طول :attribute عن :max أحرف.', 'array' => 'يجب ألا يزيد عدد عناصر :attribute عن :max.', 'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.'],
    'between' => ['numeric' => 'يجب أن يكون :attribute بين :min و:max.', 'string' => 'يجب أن يكون طول :attribute بين :min و:max أحرف.', 'array' => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و:max.', 'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.'],
    'attributes' => ['name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'title' => 'العنوان', 'description' => 'الوصف', 'reason' => 'السبب', 'status' => 'الحالة', 'priority' => 'الأولوية', 'amount' => 'المبلغ', 'quantity' => 'الكمية', 'file' => 'الملف', 'locale' => 'اللغة', 'lease_id' => 'عقد الإيجار', 'property_id' => 'العقار', 'unit_id' => 'الوحدة', 'unit_rate' => 'سعر الوحدة', 'label' => 'البند', 'note' => 'الملاحظة', 'complete' => 'الإكمال', 'is_required' => 'اشتراط الإكمال', 'version_key' => 'معرف الإصدار', 'operation_key' => 'معرف العملية', 'days' => 'أيام العمل', 'start' => 'بداية العمل', 'end' => 'نهاية العمل', 'holidays' => 'العطلات', 'response_minutes' => 'هدف الاستجابة', 'resolution_minutes' => 'هدف الإنجاز'],
];
