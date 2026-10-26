$(document).ready(()=> {
    initalRequiredSign();
});

const initalRequiredSign = ()=> {
    const elements = $('input[required],select[required]');
    for (const element of elements) {
        const parent = $(element.closest('[class^="mb-"]'));
        if(parent.find('label>.text-danger').length < 1) {
            parent.find('label').append(` <span class="text-danger">*</span>`)
        }
    }
}