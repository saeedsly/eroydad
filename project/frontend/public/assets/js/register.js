(function(){
  var inputs=document.querySelectorAll('input[name="account_type"]');
  var box=document.querySelector('.employee-fields');
  var national=document.querySelector('input[name="national_id"]');
  if(!box)return;
  function update(){
    var selected=document.querySelector('input[name="account_type"]:checked');
    var municipal=selected&&selected.value==='municipal';
    box.hidden=!municipal;
    if(national) national.required=!!municipal;
  }
  Array.prototype.forEach.call(inputs,function(input){input.addEventListener('change',update);});
  update();
})();
