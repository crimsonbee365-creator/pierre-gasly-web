/**
 * PIERRE GASLY - Enhanced Validation with Philippine Numbers
 * Fixed eye icon positioning and strict validations
 */

// Validation Rules
const ValidationRules = {
    name: {
        minLength: 3,
        maxLength: 100,
        pattern: /^[a-zA-Z\s\-'\.]+$/,
        messages: {
            minLength: 'Minimum 3 characters required',
            noNumbers: 'Names cannot contain numbers',
            valid: 'Valid name'
        }
    },
    
    email: {
        // Strict email validation - must have proper domain
        pattern: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
        messages: {
            invalid: 'Please enter a valid email address',
            invalidDomain: 'Email must have a valid domain (e.g., @gmail.com)',
            valid: 'Valid email'
        }
    },
    
    philippinePhone: {
        // Philippine mobile numbers: Must start with 09, total 11 digits
        pattern: /^09\d{9}$/,
        messages: {
            mustStart09: 'Phone number must start with 09',
            invalid: 'Invalid Philippine mobile number',
            format: 'Format: 09XX XXX XXXX (11 digits)',
            valid: 'Valid phone number'
        }
    },
    
    password: {
        minLength: 8,
        maxLength: 50,
        requireLetter: true,
        requireNumber: true,
        messages: {
            minLength: 'Minimum 8 characters required',
            requireBoth: 'Must contain letters and numbers',
            valid: 'Strong password'
        }
    },
    
    address: {
        minLength: 10,
        maxLength: 500,
        messages: {
            minLength: 'Minimum 10 characters required',
            valid: 'Valid address'
        }
    },
    
    birthday: {
        minAge: 18,
        maxAge: 100,
        messages: {
            minAge: 'Must be at least 18 years old',
            future: 'Birthday cannot be in the future',
            valid: 'Valid date'
        }
    }
};

// Utility Functions
function showMessage(input, message, isError = false) {
    let hint = input.parentElement.querySelector('.input-hint');
    
    if (!hint) {
        hint = document.createElement('div');
        hint.className = 'input-hint';
        
        // Insert after password wrapper if it exists
        const wrapper = input.closest('.password-wrapper') || input.parentElement;
        wrapper.parentElement.insertBefore(hint, wrapper.nextSibling);
    }
    
    hint.textContent = message;
    hint.className = isError ? 'input-hint error' : 'input-hint success';
    
    if (isError) {
        input.classList.add('error');
        input.classList.remove('success');
    } else {
        input.classList.remove('error');
        input.classList.add('success');
    }
}

function clearValidation(input) {
    input.classList.remove('error', 'success');
    const hint = input.parentElement.querySelector('.input-hint') || 
                 input.closest('.form-group').querySelector('.input-hint');
    if (hint) hint.textContent = '';
}

// Name Validation (Letters only)
function validateName(input) {
    const value = input.value.trim();
    const rules = ValidationRules.name;
    
    // Remove numbers automatically
    if (/\d/.test(value)) {
        input.value = value.replace(/\d/g, '');
        showMessage(input, '⚠️ ' + rules.messages.noNumbers, true);
        return false;
    }
    
    if (value.length < rules.minLength) {
        const remaining = rules.minLength - value.length;
        showMessage(input, `⚠️ ${remaining} more character${remaining > 1 ? 's' : ''} needed`, true);
        return false;
    }
    
    if (value.length > rules.maxLength) {
        showMessage(input, '⚠️ Maximum 100 characters allowed', true);
        return false;
    }
    
    if (!rules.pattern.test(value)) {
        showMessage(input, '⚠️ Only letters, spaces, and hyphens allowed', true);
        return false;
    }
    
    showMessage(input, '✓ ' + rules.messages.valid, false);
    return true;
}

// Strict Email Validation
function validateEmail(input) {
    const value = input.value.trim().toLowerCase();
    const rules = ValidationRules.email;
    
    if (!value) {
        showMessage(input, '⚠️ Email is required', true);
        return false;
    }
    
    // Check basic pattern
    if (!rules.pattern.test(value)) {
        showMessage(input, '⚠️ ' + rules.messages.invalid, true);
        return false;
    }
    
    // Check domain length (gmail.c is invalid, gmail.com is valid)
    const domain = value.split('@')[1];
    if (domain) {
        const extension = domain.split('.').pop();
        if (extension.length < 2) {
            showMessage(input, '⚠️ ' + rules.messages.invalidDomain, true);
            return false;
        }
    }
    
    showMessage(input, '✓ ' + rules.messages.valid, false);
    return true;
}

// Philippine Mobile Number Validation
function validatePhilippinePhone(input) {
    let value = input.value.replace(/[\s\-]/g, ''); // Remove spaces and dashes
    const rules = ValidationRules.philippinePhone;
    
    // Only allow digits
    value = value.replace(/\D/g, '');
    input.value = value;
    
    // Must start with 09
    if (value.length > 0 && !value.startsWith('09')) {
        showMessage(input, '⚠️ ' + rules.messages.mustStart09, true);
        return false;
    }
    
    // Check length
    if (value.length < 11) {
        const remaining = 11 - value.length;
        showMessage(input, `⚠️ ${remaining} more digit${remaining > 1 ? 's' : ''} needed`, true);
        return false;
    }
    
    if (value.length > 11) {
        showMessage(input, '⚠️ Philippine numbers are 11 digits only', true);
        return false;
    }
    
    // Final validation
    if (!rules.pattern.test(value)) {
        showMessage(input, '⚠️ ' + rules.messages.invalid, true);
        return false;
    }
    
    showMessage(input, '✓ ' + rules.messages.valid, false);
    return true;
}

// Password Validation
function validatePassword(input) {
    const value = input.value;
    const rules = ValidationRules.password;
    
    if (value.length < rules.minLength) {
        const remaining = rules.minLength - value.length;
        showMessage(input, `⚠️ ${remaining} more character${remaining > 1 ? 's' : ''} needed`, true);
        return false;
    }
    
    if (value.length > rules.maxLength) {
        showMessage(input, '⚠️ Maximum 50 characters allowed', true);
        return false;
    }
    
    const hasLetter = /[a-zA-Z]/.test(value);
    const hasNumber = /\d/.test(value);
    
    if (!hasLetter || !hasNumber) {
        showMessage(input, '⚠️ ' + rules.messages.requireBoth, true);
        return false;
    }
    
    showMessage(input, '✓ ' + rules.messages.valid, false);
    return true;
}

// Address Validation
function validateAddress(input) {
    const value = input.value.trim();
    const rules = ValidationRules.address;
    
    if (value.length < rules.minLength) {
        const remaining = rules.minLength - value.length;
        showMessage(input, `⚠️ ${remaining} more character${remaining > 1 ? 's' : ''} needed`, true);
        return false;
    }
    
    if (value.length > rules.maxLength) {
        showMessage(input, '⚠️ Maximum 500 characters allowed', true);
        return false;
    }
    
    showMessage(input, '✓ ' + rules.messages.valid, false);
    return true;
}

// Birthday Validation
function validateBirthday(input) {
    const value = input.value;
    const rules = ValidationRules.birthday;
    
    if (!value) {
        showMessage(input, '⚠️ Birthday is required', true);
        return false;
    }
    
    const birthday = new Date(value);
    const today = new Date();
    
    if (birthday > today) {
        showMessage(input, '⚠️ ' + rules.messages.future, true);
        return false;
    }
    
    const age = Math.floor((today - birthday) / (365.25 * 24 * 60 * 60 * 1000));
    
    if (age < rules.minAge) {
        showMessage(input, '⚠️ ' + rules.messages.minAge, true);
        return false;
    }
    
    if (age > rules.maxAge) {
        showMessage(input, '⚠️ Please enter a valid birthdate', true);
        return false;
    }
    
    showMessage(input, `✓ Valid (Age: ${age} years)`, false);
    return true;
}

// Enhanced Date Picker
function enhanceDatePicker(input) {
    const today = new Date().toISOString().split('T')[0];
    input.setAttribute('max', today);
    
    const minDate = new Date();
    minDate.setFullYear(minDate.getFullYear() - 100);
    input.setAttribute('min', minDate.toISOString().split('T')[0]);
    
    if (!input.parentElement.classList.contains('date-input-wrapper')) {
        const wrapper = document.createElement('div');
        wrapper.className = 'date-input-wrapper';
        input.parentElement.insertBefore(wrapper, input);
        wrapper.appendChild(input);
    }
}

// Character Counter
function addCharacterCounter(input, maxLength) {
    let counter = input.closest('.form-group').querySelector('.char-counter');
    
    if (!counter) {
        counter = document.createElement('div');
        counter.className = 'char-counter';
        const wrapper = input.closest('.password-wrapper') || input;
        wrapper.parentElement.appendChild(counter);
    }
    
    function updateCounter() {
        const length = input.value.length;
        counter.textContent = `${length} / ${maxLength} characters`;
        
        if (maxLength - length < 10) {
            counter.classList.add('warning');
        } else {
            counter.classList.remove('warning');
        }
    }
    
    input.addEventListener('input', updateCounter);
    updateCounter();
}

// Initialize All Validations
function initializeValidation() {
    console.log('Initializing validation...');
    
    // Name fields
    document.querySelectorAll('input[name="full_name"]').forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[0-9]/g, '');
            validateName(this);
        });
        
        input.addEventListener('keypress', function(e) {
            if (/[0-9]/.test(e.key)) {
                e.preventDefault();
            }
        });
        
        input.addEventListener('blur', function() {
            validateName(this);
        });
        
        addCharacterCounter(input, ValidationRules.name.maxLength);
    });
    
    // Email fields - Strict validation
    document.querySelectorAll('input[type="email"], input[name="email"]').forEach(input => {
        input.addEventListener('input', function() {
            if (this.value.length > 0) {
                clearValidation(this);
            }
        });
        
        input.addEventListener('blur', function() {
            if (this.value.trim()) {
                validateEmail(this);
            }
        });
    });
    
    // Phone fields - Philippine mobile only
    document.querySelectorAll('input[name="phone"], input[type="tel"]').forEach(input => {
        // Set placeholder
        input.placeholder = '09XX XXX XXXX';
        input.maxLength = 11;
        
        input.addEventListener('input', function() {
            validatePhilippinePhone(this);
        });
        
        input.addEventListener('keypress', function(e) {
            if (!/\d/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete') {
                e.preventDefault();
            }
        });
        
        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const cleaned = pastedText.replace(/\D/g, '');
            this.value = cleaned.substring(0, 11);
            validatePhilippinePhone(this);
        });
    });
    
    // Password fields
    document.querySelectorAll('input[name="password"], input[type="password"]').forEach(input => {
        // Skip if it's a login password
        if (input.closest('.login-container')) return;
        
        input.addEventListener('input', function() {
            validatePassword(this);
        });
        
        addCharacterCounter(input, ValidationRules.password.maxLength);
    });
    
    // Address fields
    document.querySelectorAll('textarea[name="address"]').forEach(input => {
        input.addEventListener('input', function() {
            validateAddress(this);
        });
        
        addCharacterCounter(input, ValidationRules.address.maxLength);
    });
    
    // Birthday fields
    document.querySelectorAll('input[type="date"][name="birthday"]').forEach(input => {
        enhanceDatePicker(input);
        
        input.addEventListener('change', function() {
            validateBirthday(this);
        });
    });
    
    console.log('Validation initialized successfully');
}

// Form Submission Validation
function validateForm(form) {
    let isValid = true;
    const errors = [];
    
    // Validate names
    form.querySelectorAll('input[name="full_name"]').forEach(input => {
        if (!validateName(input)) {
            isValid = false;
            errors.push('Valid name required');
        }
    });
    
    // Validate emails
    form.querySelectorAll('input[type="email"], input[name="email"]').forEach(input => {
        if (input.value && !validateEmail(input)) {
            isValid = false;
            errors.push('Valid email required');
        }
    });
    
    // Validate phone
    form.querySelectorAll('input[name="phone"]').forEach(input => {
        if (input.value && !validatePhilippinePhone(input)) {
            isValid = false;
            errors.push('Valid Philippine mobile number required');
        }
    });
    
    // Validate passwords
    form.querySelectorAll('input[name="password"]').forEach(input => {
        if (input.value && !validatePassword(input)) {
            isValid = false;
            errors.push('Valid password required');
        }
    });
    
    // Validate address
    form.querySelectorAll('textarea[name="address"]').forEach(input => {
        if (input.value && !validateAddress(input)) {
            isValid = false;
            errors.push('Valid address required');
        }
    });
    
    // Validate birthday
    form.querySelectorAll('input[name="birthday"]').forEach(input => {
        if (input.value && !validateBirthday(input)) {
            isValid = false;
            errors.push('Valid birthday required');
        }
    });
    
    if (!isValid) {
        // Scroll to first error
        const firstError = form.querySelector('.error');
        if (firstError) {
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError.focus();
        }
        
        // Show error summary
        alert('Please fix the following errors:\n\n' + [...new Set(errors)].join('\n'));
    }
    
    return isValid;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeValidation);
} else {
    initializeValidation();
}

// Add form submission handlers
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                return false;
            }
        });
    });
});

// Export for global use
window.PierreGaslyValidation = {
    validateName,
    validateEmail,
    validatePhilippinePhone,
    validatePassword,
    validateAddress,
    validateBirthday,
    validateForm
};
