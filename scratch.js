const assert = require('assert');
let thrown = false;
try {
    const page = 'trips';
    const action = 'list';
    let targetPageId = '';
    if (page === 'trips') {
        targetPageId = action === 'add' ? 'tripAddPage' : 'tripListPage';
    }
    const idsToToggle = page === 'trips' ? ['tripAddPage', 'tripListPage'] 
                      : page === 'employees' ? ['employeeAddPage', 'employeeListPage']
                      : ['vendorAddPage', 'vendorListPage'];
    
    idsToToggle.forEach(id => {
        // dummy
    });
} catch (e) {
    console.error(e);
    thrown = true;
}
console.log("Thrown?", thrown);
