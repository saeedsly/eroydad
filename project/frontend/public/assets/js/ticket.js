(function(){
  var element=document.getElementById('ticketQr');
  if(!element)return;
  var printButton=document.getElementById('printTicket');
  if(printButton) printButton.addEventListener('click',function(){window.print();});
  try{
    if(typeof qrcode!=='function') throw new Error('QR module unavailable');
    var qr=qrcode(0,'M');
    qr.addData(element.getAttribute('data-qr'),'Byte');
    qr.make();
    element.innerHTML=qr.createSvgTag(5,0);
  }catch(e){
    element.textContent='QR در این مرورگر ساخته نشد؛ کد را به پشتیبانی نشان دهید.';
    element.classList.add('qr-error');
  }
})();
