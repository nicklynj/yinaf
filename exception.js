


var exception = function() {
  if (!(this instanceof exception)) {
    var obj = new exception();
    obj.handle.apply(obj, arguments);
  }
};


exception.prototype.handle = function(exception) {
  
  alert('file:"' + exception.file + '", line:"' + exception.line + '", message:"' + exception.message + '"');
};
